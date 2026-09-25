<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Siding;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\FolderDocument;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Ledger;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\LedgerLine;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vault;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Misdeclared;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Reaction;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * What the audit trail says must have happened. Records are sent once the
 * transaction committed, a rolled-back flush leaves no trace, and a mistake in
 * an audit declaration is the writer's failure to handle — not the flush's.
 */
final class TransactionSafetyTest extends DoctrineTestCase
{
    public function testARolledBackFlushLeavesNoRecord(): void
    {
        // A listener behind ours that fails the flush after the INSERT ran.
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(LifecycleEventArgs $args): void
            {
                throw new \RuntimeException('something else in the flush broke');
            }
        });

        try {
            $this->em->persist(new Article('Hello'));
            $this->em->flush();
            self::fail('the flush should have failed');
        } catch (\RuntimeException) {
        }

        // Why: the transaction's ROLLBACK voided the INSERT in the connection's log. Not that
        // the manager is closed, nor that it was cleared on the way out -- neither decides
        // what ran.
        self::assertSame([StatementLog::VOID], $this->fatesOf('INSERT INTO Article'), 'the premise: the log says the INSERT was taken back');
        self::assertSame([], $this->documents(), 'the history must not describe a state the database never had');
    }

    public function testAFlushSomebodyElseAbortedInOnFlushDoesNotSilenceEveryFlushAfterIt(): void
    {
        // UnitOfWork::commit() dispatches onFlush, then beginTransaction(), and only
        // then enters the try whose catch calls close(). A listener behind ours that
        // throws in onFlush — a validation veto, the usual reason — therefore leaves
        // the flush with no onClear, no postFlush and an open manager. Everything this
        // listener collected stays, and so does its idea that a flush is running: every
        // flush after this one looks nested, publishes nothing, and the audit trail is
        // silent for the rest of the process without one line anywhere saying so.
        $veto = new class {
            public bool $angry = true;

            public function onFlush(): void
            {
                if ($this->angry) {
                    throw new \DomainException('this entity may not be saved');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        try {
            $this->em->persist(new Article('Refused'));
            $this->em->flush();
            self::fail('the flush should have failed');
        } catch (\DomainException) {
        }

        // The manager is still open — Doctrine never reached its own failure path — so
        // the application carries on, and so must the history.
        self::assertTrue($this->em->isOpen(), 'Doctrine did not close the manager, and this test is about what happens next');

        $veto->angry = false;
        $this->em->persist(new Article('Saved'));
        $this->em->flush();

        // Two, not one: the manager stayed open, so Doctrine still holds the insert the
        // vetoed flush scheduled and writes it now. Both rows are in the database and
        // both belong in the history — what must not happen is the nothing this
        // produced before.
        $documents = $this->documents();

        self::assertCount(2, $documents, 'the flush after an aborted one is still audited');
        self::assertSame(['Refused', 'Saved'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'], $documents));
    }

    public function testAnInnerFlushSomebodyElseAbortedDoesNotStrandTheOuterFlushesRecords(): void
    {
        // The mirror of the test above, and a hole the fix for it opened. A nested flush
        // pushes a level of its own; if a listener behind this one throws in *its*
        // onFlush and the application catches it, that level is never popped. postFlush
        // popped the top of the stack blindly, saw one left and published nothing — so
        // the outer flush, which did commit, wrote no history at all, and the flush after
        // it read the stack as abandoned and dropped what was collected.
        $veto = new class {
            public int $seen = 0;

            public function onFlush(): void
            {
                // The outer flush passes; the inner one is refused.
                if (++$this->seen === 2) {
                    throw new \DomainException('this entity may not be saved');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        $em = $this->em;
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class($em) {
            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em)
            {
            }

            public function postPersist(): void
            {
                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // The application copes and carries on, which is the whole point.
                }
            }
        });

        $this->em->persist(new Article('Hello'));
        $this->em->flush();

        self::assertCount(1, $this->documents(), 'the outer flush committed, so its record is history');
    }

    public function testAThrowingRepresenterInPostFlushDoesNotLeakIntoTheNextFlush(): void
    {
        // postFlush runs application code — a representer deferred until the element has
        // its generated id — and under "throw" reporting that failure leaves the method
        // through the exception, past the cleanup that follows it. Everything the flush
        // collected then stays: pending records, membership, change sets. The depth
        // stack was already unwound, so the next flush does not read the state as
        // abandoned either, and somebody else's operation publishes those records.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));
        $this->attachListener(FailurePolicy::Throw);

        $vault = new Vault('Contracts');
        $vault->add(new FolderDocument('lease.pdf'));

        try {
            $this->em->persist($vault);
            $this->em->flush();
            self::fail('the representer should have failed the report');
        } catch (WriteFailedException) {
        }

        $this->gateway->documents = [];

        // A different, perfectly ordinary operation. It has nothing to do with the vault
        // and must not answer for it.
        try {
            $this->em->persist(new Article('Unrelated'));
            $this->em->flush();
        } catch (WriteFailedException $inherited) {
            self::fail('the next flush inherited the failed one\'s state: '.$inherited->getMessage());
        }

        $documents = $this->documents();

        self::assertCount(1, $documents, 'one operation, one record — not the leftovers of the one that failed');
        self::assertSame('article', $documents[0]['objectType']);
    }

    public function testARepresenterCannotRefuseAFlushBecauseTheIdArrivedEarly(): void
    {
        // A representer is application code the listener runs to name an element, and
        // where it runs decides what its failure costs. For an element being inserted it
        // belongs in postFlush: the row is written, the id is final, and a failure goes
        // through the failure policy — the application's change is kept and only the
        // history of it is in question.
        //
        // Which moment that is used to be decided by asking whether the element already
        // had an identifier, and that is not the same question. An identity column gives
        // one out with the INSERT, so on MySQL, SQLite and Postgres under DBAL 4 an
        // insertion has no id in onFlush and the representer waited. A sequence hands
        // the number out at persist() time — which is how Postgres maps a generated
        // column under DBAL 3 — and an assigned identifier, as here, is there from the
        // constructor. Both looked like "not an insertion", so the representer ran in
        // onFlush, before UnitOfWork opens its transaction: raising there vetoed the
        // application's flush and the row was never written at all.
        //
        // The same code and the same configuration would then either keep the user's
        // data or throw it away depending on how the database hands out identifiers.
        $this->attachListener(FailurePolicy::Throw);

        $ledger = new Ledger('Payables');
        $line = new LedgerLine('jan', LedgerLine::UNREADABLE);
        $ledger->add($line);

        try {
            $this->em->persist($ledger);
            $this->em->flush();
            self::fail('the representer failure still has to reach the caller');
        } catch (WriteFailedException) {
        }

        // The point of the test: under "throw" the caller is told either way, but the
        // row it asked for is in the database rather than lost to an audit declaration.
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Ledger'),
            'the flush committed: a representer that fails is a problem for the history, not for the application',
        );
    }

    public function testARepresenterThatFailedIsReportedOnceAndTheLineGoesOnFromItsRow(): void
    {
        // The history is read from the connection's log, and the log is read again from its
        // start whenever a flush publishes: a failure the representer raised at one statement
        // is met again at every reading after it. It is the caller's once -- at the flush
        // that ran the statement -- and the fact it was for is left out. The row it was about
        // is not: the line has a caption again and leaves by the one it has then.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($ledger = new Ledger('Payables'));
        $ledger->add($line = new LedgerLine('jan', LedgerLine::UNREADABLE));

        try {
            $this->em->flush();
            self::fail('the representer failure reaches the caller of the flush it happened in');
        } catch (WriteFailedException) {
        }

        self::assertSame([], array_values(array_filter(array_keys($this->documents()[0]['changes'] ?? []), static fn (string $k): bool => str_starts_with($k, 'lines'))), 'the arrival it could not name is left out');

        $this->gateway->documents = [];
        $ledger->name = 'Receivables';
        $this->em->flush(); // raises if the failure is reported again

        self::assertSame([['name' => ['old' => 'Payables', 'new' => 'Receivables']]], array_map(static fn (array $d): array => $d['changes'], $this->documents()));

        $this->gateway->documents = [];
        $line->caption = 'January';
        $this->em->flush();
        $ledger->lines->removeElement($line);
        $this->em->remove($line);
        $this->em->flush();

        self::assertSame([['lines.jan' => ['old' => 'January', 'new' => null]]], array_map(static fn (array $d): array => $d['changes'], $this->documents()), 'named by the row it left as');
    }

    public function testARepresenterThatFailedInsideTheApplicationsTransactionIsReportedOnceToo(): void
    {
        // Nothing is settled inside a transaction of the application's own, so every flush
        // there replays the log from the same start and meets the failure again: it is the
        // caller's at the flush that ran the statement, and not at the ones after it.
        $this->attachListener(FailurePolicy::Throw);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        $this->em->persist($ledger = new Ledger('Payables'));
        $ledger->add(new LedgerLine('jan', LedgerLine::UNREADABLE));

        try {
            $this->em->flush();
            self::fail('the representer failure reaches the caller of the flush it happened in');
        } catch (WriteFailedException) {
        }

        $ledger->name = 'Receivables';
        $this->em->flush(); // raises if the failure is reported again
        $connection->commit();

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM LedgerLine'), 'the premise: the line committed');
    }

    public function testARecordStandsByItsOwnStatementWhenANestedFlushAheadOfThisListenerDies(): void
    {
        // The first article's UPDATE runs; a listener ahead of this one, in its postUpdate,
        // runs a nested flush that writes the second article -- the same table -- and dies.
        // By the time this listener takes the first article's record the last statement of
        // that table is the nested flush's, taken back. The record's statement is its own
        // flush's, which stayed done.
        $first = $this->persisted(new Article('First'));
        $second = $this->persisted(new Article('Second'));
        $this->gateway->documents = [];

        $ahead = new class($this->em, $first, $second) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $first, private readonly Article $second)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->second && $this->ran) {
                    throw new \DomainException('the nested flush dies after its statement');
                }

                if ($args->getObject() !== $this->first || $this->ran) {
                    return;
                }

                $this->ran = true;
                $this->second->title = 'Second, from inside';

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // what an application does about a nested flush that failed
                }
            }
        };
        $this->aheadOfTheAuditListener(Events::postUpdate, $ahead);

        $first->title = 'First, edited';
        $this->em->flush();

        self::assertSame(['First, edited', 'Second'], array_map(fn (Article $a): mixed => $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$a->id]), [$first, $second]), 'the premise: the outer flush committed and the nested one was taken back');
        self::assertSame(['First, edited'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, $this->documents()));
    }

    public function testAnUpdateTakenBackBeforeThisListenerSawItIsNotRecorded(): void
    {
        // The same savepoint of the application's, rolled back this time by a listener ahead
        // of this one -- in the second article's postUpdate, before this listener takes its
        // record. The execution the record would describe is void already when it is taken;
        // the row's older statements, from before this flush, are not what it is about.
        $first = $this->persisted(new Article('First'));
        $second = $this->persisted(new Article('Second'));
        $this->gateway->documents = [];
        $connection = $this->em->getConnection();

        $this->aheadOfTheAuditListener(Events::postUpdate, new class($first, $second, $connection) {
            public function __construct(private readonly Article $first, private readonly Article $second, private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                match ($args->getObject()) {
                    $this->first => $this->connection->beginTransaction(),
                    $this->second => $this->connection->rollBack(),
                    default => null,
                };
            }
        });

        $first->title = 'First, edited';
        $second->title = 'Second, edited';
        $this->em->flush();

        self::assertSame(['First, edited', 'Second'], array_map(fn (Article $a): mixed => $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$a->id]), [$first, $second]), 'the premise: the first was written and the second taken back');
        self::assertSame(['First, edited'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, $this->documents()));
    }

    public function testEachRecordOfOneRowIsTiedToItsOwnExecutionWhateverTheOrderOfTheListeners(): void
    {
        // The outer flush writes the article 'One' -> 'Two'. A listener ahead of this one, in
        // its postUpdate, opens a savepoint of the application's and runs a nested flush that
        // writes the same row 'Two' -> 'Five' and succeeds; this listener takes the nested
        // record, then -- the outer announcement going on -- the outer one. A listener behind
        // this one then rolls the application's savepoint back: the nested execution is taken
        // back, the outer one stands.
        //
        // When the outer record is taken both statements are alive and the nested one is the
        // later. The record's own is the earlier: Doctrine announces a statement right after
        // it runs, so whatever lies between the outer UPDATE and this listener is somebody
        // else's -- and the nested record has taken its own already. So: each record tied to
        // its own position, and the history is exactly the outer change.
        $article = $this->persisted(new Article('One'));
        $this->gateway->documents = [];
        $connection = $this->em->getConnection();
        $bound = $this->theExecutionsTheRecordsAreTiedTo();

        $ahead = new class($this->em, $article, $connection) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $article, private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->article || $this->ran) {
                    return;
                }

                $this->ran = true;
                $this->connection->beginTransaction();
                $this->article->title = 'Five';
                $this->em->flush();
            }
        };
        $this->aheadOfTheAuditListener(Events::postUpdate, $ahead);

        $behind = new class($article, $connection) {
            private int $seen = 0;

            public function __construct(private readonly Article $article, private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                // The nested announcement comes first, then the outer one: roll back after the
                // outer, which is the second this listener sees.
                if ($args->getObject() === $this->article && ++$this->seen === 2) {
                    $this->connection->rollBack();
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $behind);

        $article->title = 'Two';
        $this->em->flush();

        self::assertSame('Two', $connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), 'the premise: the outer flush committed and the nested execution was taken back');

        // Two records were taken, each tied to a statement of its own, and never one statement
        // for both: the positions, not how many documents stand, say whether two records leaned
        // on one execution.
        $positions = array_map(static fn (array $b): mixed => $b['statement'][0] ?? null, $bound->getArrayCopy());
        sort($positions);
        self::assertSame(['Five', 'Two'], $positions, 'each record is tied to an execution of its own');
        self::assertCount(1, $this->documents(), 'and only the outer one stood');
    }

    public function testARecordIsTiedToItsOwnRowAndNotToAnEarlierOneOfItsTableNobodyRecorded(): void
    {
        // One flush updates two articles. The first changes only a field nobody audits, so no
        // record is taken for it and nothing ties its statement to anything; the application
        // opens a savepoint in its preUpdate and rolls back to it in its postUpdate. The
        // second article's statement is later, and the earliest statement of the table nobody
        // has taken is the first article's, void. The second record is its own row's.
        $first = $this->persisted(new Article('First'));
        $second = $this->persisted(new Article('Second'));
        $this->gateway->documents = [];
        $connection = $this->em->getConnection();

        $this->aheadOfTheAuditListener(Events::preUpdate, new class($first, $connection) {
            public function __construct(private readonly Article $first, private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function preUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->first) {
                    $this->connection->beginTransaction();
                }
            }
        });
        $this->aheadOfTheAuditListener(Events::postUpdate, new class($first, $connection) {
            public function __construct(private readonly Article $first, private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->first) {
                    $this->connection->rollBack();
                }
            }
        });

        $first->views = 7; // not audited
        $second->title = 'Second, edited';
        $this->em->flush();

        self::assertSame([0, 'Second, edited'], [(int) $connection->fetchOne('SELECT views FROM Article WHERE id = ?', [$first->id]), $connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$second->id])], 'the premise: the first was taken back and the second written');
        self::assertSame(['Second, edited'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, $this->documents()));
    }

    public function testARecordIsTiedToItsOwnTableAndNotToAnEarlierRowOfAnotherWithTheSameKey(): void
    {
        // In the article's preUpdate the application writes a row of another table with the
        // same key, inside a savepoint it rolls back at once. That statement is earlier than
        // the article's and bound to a row keyed like it; it is not the article's row.
        $article = $this->persisted(new Article('First'));
        $this->em->persist($vehicle = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vehicle());
        $this->em->flush();
        $this->gateway->documents = [];
        $connection = $this->em->getConnection();
        self::assertSame($article->id, $vehicle->id, 'the premise: the two rows share a key');

        $this->aheadOfTheAuditListener(Events::preUpdate, new class($connection, $vehicle->id) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly mixed $id)
            {
            }

            public function preUpdate(LifecycleEventArgs $args): void
            {
                $this->connection->beginTransaction();
                $this->connection->executeStatement('UPDATE Vehicle SET plate = ? WHERE id = ?', ['taken back', $this->id]);
                $this->connection->rollBack();
            }
        });

        $article->title = 'First, edited';
        $this->em->flush();

        self::assertSame(['First, edited'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, $this->documents()));
    }

    public function testAJoinedEntitysRecordIsTiedToEveryStatementOfItsChange(): void
    {
        // The ordinary case of the same hierarchy: a creation is an INSERT per table, and a
        // change of a column in each table an UPDATE per table -- one execution each time,
        // written back to back, and the record tied to all of it.
        $bound = $this->theExecutionsTheRecordsAreTiedTo();

        $press = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Press('One');
        $this->em->persist($press);
        $this->em->flush();

        self::assertSame([['insert Machine', 'insert Press']], array_map(static fn (array $b): array => $b['tied'], $bound->getArrayCopy()), 'the creation');

        $press->name = 'Two';
        $press->tonnage = 5;
        $this->em->flush();

        $tied = array_map(static fn (array $b): array => $b['tied'], $bound->getArrayCopy());
        self::assertCount(1, $tied);
        sort($tied[0]);
        self::assertSame(['update Machine', 'update Press'], $tied[0], 'the change of both tables');
    }

    public function testAJoinedEntitysRecordIsTiedToTheStatementsOfItsOwnChangeAndNoOther(): void
    {
        // A JOINED hierarchy writes an entity's change as one statement per table it touches.
        // The outer flush changes only the root's column; a listener ahead of this one runs a
        // nested flush that changes only the subclass's column, and dies. Each table has one
        // statement of this row nobody has taken -- and only one of them is the outer change:
        // the subclass table's is the dead nested flush's. The outer record is tied to the root
        // table's statement alone, and stands.
        $press = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Press('One');
        $this->em->persist($press);
        $this->em->flush();
        $this->gateway->documents = [];
        $bound = $this->theExecutionsTheRecordsAreTiedTo();

        $ahead = new class($this->em, $press) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly object $press)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->press) {
                    return;
                }

                if ($this->ran) {
                    throw new \DomainException('the nested flush dies after its statement');
                }

                $this->ran = true;
                $this->press->tonnage = 9; // @phpstan-ignore property.notFound

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // what an application does about a nested flush that failed
                }
            }
        };
        $this->aheadOfTheAuditListener(Events::postUpdate, $ahead);

        $press->name = 'Two';
        $this->em->flush();

        $connection = $this->em->getConnection();
        self::assertSame(['Two', 1], [$connection->fetchOne('SELECT name FROM Machine WHERE id = ?', [$press->id]), (int) $connection->fetchOne('SELECT tonnage FROM Press WHERE id = ?', [$press->id])], 'the premise: the outer change committed and the nested one was taken back');
        self::assertSame([['update Machine']], array_map(static fn (array $b): array => $b['tied'], $bound->getArrayCopy()), 'the outer record is tied to its own statement and not the nested one\'s');
        self::assertCount(1, $this->documents(), 'and it stood');
    }

    public function testARemovalIsTiedToItsDeleteAndNotToAnEarlierUpdateOfItsRow(): void
    {
        // In one flush the article's UPDATE runs -- of a field nobody audits, so no record is
        // taken for it -- inside a savepoint of the application's that is rolled back at once,
        // and a listener then removes the article, whose DELETE runs in the same flush. The
        // earliest statement of that row nobody has taken is the UPDATE, void; the removal's
        // own is the DELETE. A record is tied to a statement of its own kind.
        $article = $this->persisted(new Article('Doomed'));
        $id = $article->id;
        $this->gateway->documents = [];
        $connection = $this->em->getConnection();
        $bound = $this->theExecutionsTheRecordsAreTiedTo();

        $this->aheadOfTheAuditListener(Events::preUpdate, new class($connection) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function preUpdate(LifecycleEventArgs $args): void
            {
                $this->connection->beginTransaction();
            }
        });
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $connection) {
            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                $this->connection->rollBack();
                $this->em->remove($args->getObject());
            }
        });

        $article->views = 7; // not audited
        $this->em->flush();

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$id]), 'the premise: the DELETE committed');
        self::assertSame([['delete Article']], array_map(static fn (array $b): array => $b['tied'], $bound->getArrayCopy()));
        self::assertSame(['remove'], array_map(static fn (array $d): string => $d['event'], $this->documents()));
    }

    public function testTwoUpdatesOfOneRowAreTwoExecutionsAndOnlyTheOneTakenBackGoes(): void
    {
        // The outer flush writes the article 'One' -> 'Two'; a listener ahead of this one, in
        // its postUpdate, runs a nested flush that writes the SAME row 'Two' -> 'Five' and
        // dies. Same table, same key -- the row does not tell the two apart, and neither
        // does the owner, since a flush that dies before claiming what it ran leaves it to
        // the frame around it. What does is where each ran: the outer flush's statement is in
        // the transaction still open when this listener takes its record, the nested one's
        // in a savepoint already rolled back. One record, tied to the outer flush's statement.
        //
        // And then the row really does go 'Two' -> 'Five', in a flush of its own that
        // commits: the same transition as the one taken back, and a fact this time.
        //
        // What the record SAYS is another matter, and step 5's: an entity's fields are still
        // read from Doctrine's change set, and the nested flush left 'Five' on the object, so
        // the outer record says 'One' -> 'Five' where the row took 'Two'. Pinned as it is today
        // -- the fate is this test's, and the value flips when the fields come from the log.
        $article = $this->persisted(new Article('One'));
        $this->gateway->documents = [];

        $ahead = new class($this->em, $article) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $article)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->article) {
                    return;
                }

                if ($this->ran) {
                    throw new \DomainException('the nested flush dies after its statement');
                }

                $this->ran = true;
                $this->article->title = 'Five';

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // what an application does about a nested flush that failed
                }
            }
        };
        $this->aheadOfTheAuditListener(Events::postUpdate, $ahead);

        $article->title = 'Two';
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate], $ahead);

        self::assertSame('Two', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), 'the premise: the outer flush committed and the nested one was taken back');
        self::assertCount(1, $this->documents(), 'one record: the outer flush\'s execution stood, the nested one\'s did not');

        $this->reopen();
        $again = $this->em->find(Article::class, $article->id);
        self::assertInstanceOf(Article::class, $again);
        $again->title = 'Five';
        $this->em->flush();

        $said = array_map(static fn (array $d): array => [$d['changes']['title']['old'] ?? null, $d['changes']['title']['new'] ?? null], $this->documents());

        self::assertCount(2, $said, 'and the row\'s real move to Five is a record of its own, once');
        self::assertNotSame([['One', 'Two'], ['Two', 'Five']], $said, 'this is described correctly now: step 5 is done here, take the pin off');
        self::assertSame([['One', 'Five'], ['Two', 'Five']], $said, 'what the record says has changed; the pin no longer describes it');
    }

    public function testARemovalStandsByItsOwnDeleteWhenANestedFlushAheadOfThisListenerDies(): void
    {
        // The same for a removal, whose row's key Doctrine has already cleared by postRemove:
        // the first article's DELETE runs, and a listener ahead of this one, in its
        // postRemove, runs a nested flush that writes the second article and dies. The
        // record's statement is found by the key the row had, taken before the DELETE.
        $first = $this->persisted(new Article('First'));
        $second = $this->persisted(new Article('Second'));
        $firstId = $first->id;
        $this->gateway->documents = [];

        $ahead = new class($this->em, $second) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $second)
            {
            }

            public function postRemove(LifecycleEventArgs $args): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;
                $this->second->title = 'Second, from inside';
                $this->em->getEventManager()->addEventListener([Events::postUpdate], $this);

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // what an application does about a nested flush that failed
                } finally {
                    $this->em->getEventManager()->removeEventListener([Events::postUpdate], $this);
                }
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                throw new \DomainException('the nested flush dies after its statement');
            }
        };
        $this->aheadOfTheAuditListener(Events::postRemove, $ahead);

        $this->em->remove($first);
        $this->em->flush();

        self::assertSame([0, 'Second'], [(int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$firstId]), $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$second->id])], 'the premise: the DELETE committed and the nested flush was taken back');
        self::assertSame(['remove'], array_map(static fn (array $d): string => $d['event'], $this->documents()));
    }

    public function testARecordStandsByAStatementOfItsOwnTableAndNotByWhateverRanAfterIt(): void
    {
        // A listener ahead of this one opens a savepoint in the article's postUpdate and writes
        // a row of another table in it -- with the same key as the article's, the way a
        // persister writes it -- and a listener behind this one takes it back. When this
        // listener takes the article's record, that statement is the last one, bound to a row
        // of the same key and not void yet; the record is about the article's row, which
        // stayed written.
        $article = $this->persisted(new Article('First'));
        $this->em->persist($vehicle = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vehicle());
        $this->em->flush();
        $this->gateway->documents = [];
        $connection = $this->em->getConnection();
        self::assertSame($article->id, $vehicle->id, 'the premise: the two rows share a key');

        $this->aheadOfTheAuditListener(Events::postUpdate, new class($connection, $vehicle->id) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly mixed $id)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                $this->connection->beginTransaction();
                $this->connection->executeStatement('UPDATE Vehicle SET plate = ? WHERE id = ?', ['taken back', $this->id]);
            }
        });
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($connection) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                $this->connection->rollBack();
            }
        });

        $article->title = 'First, edited';
        $this->em->flush();

        self::assertSame(['First, edited', 'AA-1'], [$connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), $connection->fetchOne('SELECT plate FROM Vehicle WHERE id = ?', [$vehicle->id])], 'the premise: the article was written and the vehicle taken back');
        self::assertSame(['First, edited'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, $this->documents()));
    }

    public function testARemovalTheApplicationTookBackInsideAFlushIsNotRecorded(): void
    {
        // One flush edits one article and removes another; Doctrine writes updates before
        // deletions. The application opens a savepoint in the edited one's postUpdate, so the
        // DELETE runs inside it, and rolls back to it in the removed one's postRemove, behind
        // this listener: the DELETE is taken back after this listener tied the removal's
        // record to it -- by the key the row had, since Doctrine cleared the generated
        // identifier before postRemove.
        $edited = $this->persisted(new Article('Edited'));
        $removed = $this->persisted(new Article('Removed'));
        $removedId = $removed->id;
        $this->gateway->documents = [];
        $connection = $this->em->getConnection();

        $this->em->getEventManager()->addEventListener([Events::postUpdate, Events::postRemove], new class($connection) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                $this->connection->beginTransaction();
            }

            public function postRemove(LifecycleEventArgs $args): void
            {
                $this->connection->rollBack();
            }
        });

        $edited->title = 'Edited, again';
        $this->em->remove($removed);
        $this->em->flush();

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$removedId]), 'the premise: the DELETE was taken back');
        self::assertSame([['update', (string) $edited->id]], array_map(static fn (array $d): array => [$d['event'], (string) $d['objectId']], $this->documents()));
    }

    public function testAnElementThatBroughtItsOwnIdIsStillNamedByItsRepresenter(): void
    {
        // The other side of the change above: waiting for postFlush must not cost the
        // name. It is read there from the element, which still has everything it had.
        $ledger = new Ledger('Payables');
        $ledger->add(new LedgerLine('jan', 'January'));

        $this->em->persist($ledger);
        $this->em->flush();

        $documents = $this->documents();

        self::assertCount(1, $documents);
        self::assertSame(['old' => null, 'new' => 'January'], $documents[0]['changes']['lines.jan'] ?? null);
    }

    public function testRecordsAreSentAfterTheCommit(): void
    {
        $nestingAtWrite = null;
        $connection = $this->em->getConnection();
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(LifecycleEventArgs $args): void
            {
                // still inside the transaction here
            }
        });

        $this->gateway->respondToSearch = null;
        $this->gateway->onIndex = static function () use (&$nestingAtWrite, $connection): void {
            $nestingAtWrite = $connection->getTransactionNestingLevel();
        };

        $this->em->persist(new Article('Hello'));
        $this->em->flush();

        self::assertSame(0, $nestingAtWrite, 'the write happened after the commit, not inside the transaction');
        self::assertCount(1, $this->documents());
    }

    /**
     * The documented boundary, made executable: the listener writes when the flush's
     * own transaction commits, and it cannot know about a wider transaction around it.
     * Roll that one back and the database forgets the row while the index keeps the
     * record — a history entry for a state that never was. This test exists to fail
     * the day that stops being true, and the two after it show the recipe that closes
     * the gap today. A transaction-aware delivery (an outbox) is post-1.0 work.
     */
    public function testAnOuterTransactionRolledBackLeavesTheRecordBehind(): void
    {
        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->persist(new Article('Never committed'));
            $this->em->flush();
        } finally {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
        }

        self::assertCount(1, $this->documents(), 'the limitation, stated as a fact: the record went out when the inner flush committed');
        self::assertSame([], $this->em->getRepository(Article::class)->findAll(), 'while the database kept nothing');
    }

    public function testTheGapIsTheSameWhicheverWayTheTwoAreNested(): void
    {
        // wrapInTransaction() around coalesce(), rather than the other way round. The
        // frame and the transaction are independent of each other, so both orders reach
        // the same place — but "both orders" is exactly the kind of claim that is assumed
        // rather than checked, and an audit trail whose correctness depended on which one
        // an application happened to write would be a trap nobody could see.
        $buffer = new FrameBuffer();
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer));

        try {
            $this->em->wrapInTransaction(function () use ($frame): void {
                $frame->coalesce(function (): void {
                    $this->em->persist(new Article('Never committed'));
                    $this->em->flush();
                    throw new \RuntimeException('the business operation failed');
                });
            });
            self::fail('the operation should have failed');
        } catch (\RuntimeException) {
            $this->em->clear();
        }

        // coalesce() closes its frame in a finally, so the records went out when the
        // inner flush committed — the documented limitation, reached from the other
        // direction. The recipe below is what closes it, and it works the same way here:
        // it is reset() that decides, not the nesting.
        self::assertCount(1, $this->documents(), 'the same limitation, whichever way round the two are written');
        self::assertSame([], $this->em->getRepository(Article::class)->findAll());
    }

    public function testTheFrameRecipeClosesThatGap(): void
    {
        // What the README prescribes for an application that owns the wider
        // transaction: hold the records in a frame, and drop them if it rolls back.
        $buffer = new FrameBuffer();
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer));

        $frame->begin();
        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->persist(new Article('Never committed'));
            $this->em->flush();
            throw new \RuntimeException('the business operation failed');
        } catch (\RuntimeException) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
            $frame->reset(); // rolled back: the records describe nothing that happened
        }

        self::assertSame([], $this->documents(), 'reset() drops what the rollback undid');
    }

    public function testWithoutAtomicityTheFrameIsNotABufferAndTheRecipeLeaks(): void
    {
        // The hole in the recipe as it was written, pinned rather than argued about.
        // A frame holds records back, but on_overflow: release never promised it holds
        // ALL of them: a remove ends the held record for that object and both go out
        // where they happen, before end() and beyond the reach of reset(). An outer
        // transaction that rolls back afterwards leaves them in the index describing
        // an object the database still has.
        $buffer = new FrameBuffer();   // the default: release
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer));

        $frame->begin();
        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->persist($article = new Article('Never committed'));
            $this->em->flush();

            $this->em->remove($article);
            $this->em->flush();

            throw new \RuntimeException('the business operation failed');
        } catch (\RuntimeException) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
            $frame->reset();
        }

        self::assertNotSame([], $this->documents(), 'the remove had already left the frame; reset() could not take it back');
        self::assertSame([], $this->em->getRepository(Article::class)->findAll(), 'while the database has nothing at all');
    }

    public function testAnAtomicFrameHoldsEverythingAndTheRecipeHolds(): void
    {
        // The same operation with what the recipe asks for. In an atomic frame nothing
        // leaves before it closes - a remove and an actor boundary are staged rather than
        // written where they happen - so reset() can still take all of it back. The
        // configuration is left alone: this is one operation's request, not a deployment's
        // answer about the valve.
        $buffer = new FrameBuffer();
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer));

        $frame->begin(atomic: true);
        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->persist($article = new Article('Never committed'));
            $this->em->flush();

            $this->em->remove($article);
            $this->em->flush();

            self::assertSame([], $this->documents(), 'nothing leaves the frame, not even the remove');

            throw new \RuntimeException('the business operation failed');
        } catch (\RuntimeException) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
            $frame->reset();
        }

        self::assertSame([], $this->documents(), 'and reset() drops all of it');
    }

    public function testTheSameRecipeWritesWhenTheOuterTransactionCommits(): void
    {
        $buffer = new FrameBuffer();
        $frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer));

        $frame->begin();
        $this->em->getConnection()->beginTransaction();
        $this->em->persist(new Article('Committed'));
        $this->em->flush();
        $this->em->getConnection()->commit();
        $frame->end(); // committed: now the history may speak

        self::assertCount(1, $this->documents());
    }

    public function testAFailedFlushDoesNotLeakAnElementItNeverInsertedIntoTheNextOne(): void
    {
        $shipment = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment('SH-9');
        $this->em->persist($shipment);
        $this->em->flush();
        $this->gateway->documents = [];

        $poison = new class {
            public bool $armed = true;

            public function postPersist(LifecycleEventArgs $args): void
            {
                if ($this->armed) {
                    throw new \RuntimeException('boom');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postPersist], $poison);

        try {
            $shipment->add(new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine('nut', 1)); // the INSERT is rolled back
            $this->em->flush();
        } catch (\RuntimeException) {
        }

        $this->reopen();
        $poison->armed = false;

        $again = $this->em->find(\Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment::class, $shipment->id);
        $again->reference = 'SH-9b';
        $this->em->flush();

        self::assertCount(1, $this->documents());
        self::assertSame(['reference'], array_keys($this->documents()[0]['changes']), 'a line the database never had must not appear as added');
    }

    public function testAFailedFlushDoesNotLeakIntoTheNextOne(): void
    {
        $poison = new class {
            public bool $armed = true;

            public function postPersist(LifecycleEventArgs $args): void
            {
                if ($this->armed) {
                    throw new \RuntimeException('boom');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postPersist], $poison);

        try {
            $this->em->persist(new Article('First'));
            $this->em->flush();
        } catch (\RuntimeException) {
        }

        // Doctrine closed the manager; the application gets a fresh one (resetManager) and flushes again.
        $this->reopen();
        $poison->armed = false;

        $this->em->persist(new Article('Second'));
        $this->em->flush();

        self::assertCount(1, $this->documents());
        self::assertSame(['old' => null, 'new' => 'Second'], $this->documents()[0]['changes']['title']);
    }

    /**
     * ORM 2 only: a clear of one class inside a flush that goes on to commit. What that
     * flush wrote is history -- the clear forgets Doctrine's objects of that class, and
     * decides nothing about the rows.
     */
    public function testAPartialClearInsideAFlushThatCommitsKeepsItsRecords(): void
    {
        self::skipUnlessPartialClearsExist();

        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(LifecycleEventArgs $args): void
            {
                $args->getObjectManager()->clear(Article::class); // @phpstan-ignore-line ORM 2 signature
            }
        });

        $this->em->persist(new Article('Hello'));
        $this->em->flush();

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Article'), 'the premise: the row committed');
        self::assertSame(['Hello'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, $this->documents()));
    }

    /**
     * ORM 2 only: the same partial clear inside a flush that then dies. Nothing is written,
     * and the reason is the ROLLBACK in the log -- not the manager being closed, which is
     * what the listener used to look at.
     */
    public function testAPartialClearInsideAFlushThatDiesLeavesNothingBecauseTheLogTookItBack(): void
    {
        self::skipUnlessPartialClearsExist();

        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(LifecycleEventArgs $args): void
            {
                $args->getObjectManager()->clear(Article::class); // @phpstan-ignore-line ORM 2 signature

                throw new \RuntimeException('something else in the flush broke');
            }
        });

        try {
            $this->em->persist(new Article('Hello'));
            $this->em->flush();
            self::fail('the premise: the flush died');
        } catch (\RuntimeException) {
        }

        self::assertSame([StatementLog::VOID], $this->fatesOf('INSERT INTO Article'), 'the premise: the log says the INSERT was taken back');
        self::assertSame([], $this->documents());
    }

    public function testARemovalIsRecordedThoughSomebodyClearedTheManagerAheadOfThisListener(): void
    {
        // The DELETE has run when postRemove is raised, and a listener ahead of this one
        // clears the manager there. The record drafted in preRemove has not been taken up
        // yet -- this listener's postRemove is next -- and the removal is real: the clear
        // forgets Doctrine's objects, not what the connection did.
        $article = $this->persisted(new Article('Hello'));
        $id = $article->id;
        $this->gateway->documents = [];

        $clearing = new class {
            public function postRemove(LifecycleEventArgs $args): void
            {
                $args->getObjectManager()->clear();
            }
        };
        $events = $this->em->getEventManager();
        $there = $events->getListeners(Events::postRemove);

        foreach ($there as $one) {
            $events->removeEventListener([Events::postRemove], $one);
        }

        $events->addEventListener([Events::postRemove], $clearing);

        foreach ($there as $one) {
            $events->addEventListener([Events::postRemove], $one);
        }

        $this->em->remove($article);
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$id]), 'the premise: the row went');
        self::assertSame(['remove'], array_map(static fn (array $d): string => $d['event'], $this->documents()));
    }

    public function testAnEntityWhoseIdentifierWasHandedOutBeforeAClearIsStillCreatedAfterIt(): void
    {
        // A line whose identifier is assigned is persisted, the flush is refused, and the
        // manager is cleared. The line is persisted again, as a new object, and written: that
        // INSERT is an arrival -- the row did not exist -- and not a change to a row the
        // listener half-remembered from the flush that never ran. On PostgreSQL under DBAL 3
        // a generated identifier is handed out the same way, at persist().
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($ledger = new Ledger('Payables'));
        $ledger->add(new LedgerLine('jan', 'January'));

        $veto = new class {
            public function onFlush(): void
            {
                throw new \DomainException('this flush is refused');
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        try {
            $this->em->flush();
            self::fail('the premise: the flush was refused');
        } catch (\DomainException) {
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $veto);
        }

        $this->em->clear();

        $this->em->persist($again = new Ledger('Payables'));
        $again->add(new LedgerLine('jan', 'January'));
        $this->em->flush();

        self::assertSame(
            [['create', ['old' => null, 'new' => 'January']]],
            array_map(static fn (array $d): array => [$d['event'], $d['changes']['lines.jan'] ?? null], $this->documents()),
        );
    }

    public function testAnUpdateTheApplicationTookBackInsideAFlushIsNotRecordedAndTheFlushsOthersAre(): void
    {
        // One flush updates two articles; the application opens a savepoint in the first
        // one's postUpdate and rolls back to it in the second's. The flush commits with the
        // first written and the second taken back -- so a record stands by the statement it
        // was built from, not by whether its flush ran anything at all.
        $first = $this->persisted(new Article('First'));
        $second = $this->persisted(new Article('Second'));
        $this->gateway->documents = [];
        $connection = $this->em->getConnection();

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($first, $second, $connection) {
            public function __construct(private readonly Article $first, private readonly Article $second, private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                match ($args->getObject()) {
                    $this->first => $this->connection->beginTransaction(),
                    $this->second => $this->connection->rollBack(),
                    default => null,
                };
            }
        });

        $first->title = 'First, edited';
        $second->title = 'Second, edited';
        $this->em->flush();

        self::assertSame(['First, edited', 'Second'], array_map(fn (Article $a): mixed => $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$a->id]), [$first, $second]), 'the premise: the first was written and the second taken back');
        self::assertSame(['First, edited'], array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, $this->documents()));
    }

    public function testAnEntityIdentifiedByAnAssociationIsAudited(): void
    {
        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $this->em->persist(new Reaction($article, 'like'));
        $this->em->flush();

        $document = $this->lastDocument();

        self::assertSame('reaction', $document['objectType']);
        self::assertSame($article->id.'|like', $document['objectId'], 'a part holding no delimiter is written as it always was');
        self::assertSame(['old' => null, 'new' => 1], $document['changes']['count']);
    }

    public function testTwoCompositeKeysThatUsedToShareOneIdentityNoLongerDo(): void
    {
        // ["a|b", "c"] and ["a", "b|c"] both joined to a|b|c, so two entities answered to
        // one objectId and their histories were one history.
        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $this->em->persist(new Reaction($article, 'a|b'));
        $this->em->flush();

        self::assertSame($article->id.'|a\|b', $this->lastDocument()['objectId']);
    }

    public function testOneBrokenRepresenterDoesNotCostTheFlushItsOtherHistory(): void
    {
        // A mixed flush: an ordinary article with nothing wrong with it, and a vault
        // whose element representer throws. The representer is the application's code
        // and it runs in postFlush, after the commit — so by the time it fails, both
        // rows are in the database and the article's record is already built.
        //
        // Under on_failure: throw, reporting that failure raises, and raising while the
        // records are still being assembled abandons every one of them. The article is
        // saved and its history is gone, which is the outcome the whole listener is
        // written to avoid. The failure still has to reach the caller — it is the point
        // of that setting — but after the records that were fine have gone out.
        $this->attachListener(FailurePolicy::Throw);

        $vault = new Vault('v');
        $this->em->persist($vault);
        $this->em->flush();

        $this->gateway->documents = [];

        $article = new Article('Perfectly fine');
        $this->em->persist($article);

        $vault->documents->add($document = new FolderDocument('d'));
        $document->vault = $vault;
        $this->em->persist($document);

        $raised = null;

        try {
            $this->em->flush();
        } catch (WriteFailedException $e) {
            $raised = $e;
        }

        self::assertNotNull($raised, 'the broken representer must still reach the caller');
        // The table name comes from the mapping rather than from memory: SQLite does not
        // care what case it is written in and MySQL on Linux does, which is the kind of
        // difference the database matrix exists to find — and did.
        $table = $this->em->getClassMetadata(Article::class)->getTableName();

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(sprintf("SELECT COUNT(*) FROM %s WHERE title = 'Perfectly fine'", $table)), 'the premise: the row committed');

        self::assertSame(['Perfectly fine'], array_values(array_filter(array_map(
            static fn (array $d): mixed => $d['changes']['title']['new'] ?? null,
            $this->documents(),
        ))), 'the history of everything else in the flush went with the one record that could not be built');
    }

    public function testARecordThatCannotBeBuiltAtAllCostsTheFlushNoOtherHistoryEither(): void
    {
        // The other road to the same loss. Vault's elements are inserted, so its broken
        // representer runs against a record already in the list; a siding emptied on its
        // own gets no lifecycle event at all, so its record is built from the map of
        // emptied collections — and the representer fails while it is being built.
        //
        // Reporting that raises under on_failure: throw just the same, and the article
        // beside it is just as innocent.
        // Built while the policy still only logs: creating it represents the plank too,
        // and this test is about the emptying rather than about the insert.
        $siding = new Siding('S-1');
        $siding->planks->add($plank = new FolderDocument('p'));

        $this->em->persist($plank);
        $this->em->persist($siding);
        $this->em->flush();

        $this->attachListener(FailurePolicy::Throw);

        $this->gateway->documents = [];

        $article = new Article('Perfectly fine');
        $this->em->persist($article);
        $siding->planks->clear();

        $raised = null;

        try {
            $this->em->flush();
        } catch (WriteFailedException $e) {
            $raised = $e;
        }

        self::assertNotNull($raised, 'the representer that could not build a record must still reach the caller');

        self::assertSame(['Perfectly fine'], array_values(array_filter(array_map(
            static fn (array $d): mixed => $d['changes']['title']['new'] ?? null,
            $this->documents(),
        ))), 'the history of everything else in the flush went with the record that could not be built');
    }

    public function testTheFailureTheCallerGetsIsTheFirstOneTheFlushHit(): void
    {
        // Two records that cannot be built in the same flush, on the two different roads
        // to that: a vault whose element representer runs after the commit, and a siding
        // whose record is built from nothing but its emptied collection. Both are held
        // and only one can be raised.
        //
        // The first, because the ones behind it happened later and an operator reading a
        // failure transport is looking for what went wrong rather than for what went
        // wrong last. Told apart by what the two sentences name: a deferred representer
        // has no record to name yet, and the other one does.
        $siding = new Siding('S-1');
        $siding->planks->add($plank = new FolderDocument('p'));

        $vault = new Vault('v');

        $this->em->persist($plank);
        $this->em->persist($siding);
        $this->em->persist($vault);
        $this->em->flush();

        $this->attachListener(FailurePolicy::Throw);

        $this->gateway->documents = [];

        $vault->documents->add($document = new FolderDocument('d'));
        $document->vault = $vault;
        $this->em->persist($document);

        $siding->planks->clear();

        $raised = null;

        try {
            $this->em->flush();
        } catch (WriteFailedException $e) {
            $raised = $e;
        }

        self::assertNotNull($raised);
        self::assertStringContainsString('could not be built', $raised->getMessage(), 'the caller was handed the last failure of the flush rather than its first');
        self::assertStringNotContainsString('siding', $raised->getMessage());
    }

    public function testAMistakeInTheAuditDeclarationIsLoggedNotFatal(): void
    {
        $entity = new Misdeclared();
        $this->em->persist($entity);
        $this->em->flush();

        self::assertNotNull($entity->id, 'the business operation went through');
        self::assertSame([], $this->documents());
        self::assertCount(1, $this->logs);
        self::assertStringContainsString('"nope" is listed as always recorded', $this->logs[0]);
    }

    public function testWithTheThrowPolicyAMistakeInTheDeclarationAbortsTheFlush(): void
    {
        $this->em->getEventManager()->removeEventListener(\Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber::EVENTS, ...$this->listeners());
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('"nope" is listed as always recorded');

        $this->em->persist(new Misdeclared());
        $this->em->flush();
    }

    private static function skipUnlessPartialClearsExist(): void
    {
        if (!method_exists(OnClearEventArgs::class, 'clearsAllEntities')) {
            self::markTestSkipped('Partial clears exist only in ORM 2; the lowest-dependencies CI job covers this.');
        }
    }

    /**
     * A listener of its own, not registered with the event manager, so the test decides
     * which events it sees and in which order.
     */
    public function testAFlushThatCommittedIsNotDroppedBecauseSomebodyElsesPostFlushThrew(): void
    {
        // The worst shape an audit bug can take: the database moved and the history did
        // not. Doctrine commits, then dispatches postFlush; the event manager runs
        // listeners in order and does not catch anything, so a listener registered before
        // this one throwing means this one never runs. The state it had collected then
        // sat there until the next flush, which read it as "a flush that never committed"
        // and dropped it — with a log line saying those changes never reached the
        // database, which was exactly wrong.
        //
        // The manager tells the two apart: UnitOfWork::commit() closes it on every
        // failure inside its try. Still open means the transaction went through.
        $this->attachListener(FailurePolicy::Log);

        $boom = new class {
            public bool $armed = true;

            public function postFlush(): void
            {
                if ($this->armed) {
                    $this->armed = false;

                    throw new \RuntimeException('somebody else exploded in postFlush');
                }
            }
        };

        $article = new Article('before');
        $this->em->persist($article);
        $this->em->flush();
        $this->gateway->documents = [];

        $this->beforeTheAuditListener($boom);

        $article->title = 'committed';

        try {
            $this->em->flush();
            self::fail('the foreign listener should have thrown');
        } catch (\RuntimeException) {
        }

        self::assertSame('committed', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]), 'the row is in the database');

        // Anything at all afterwards: the record must not be thrown away by it.
        $this->em->persist(new Article('the next flush'));
        $this->em->flush();

        $titles = array_map(static fn (array $d): mixed => $d['changes']['title']['new'] ?? null, $this->documents());

        self::assertContains('committed', $titles, 'the committed change is in the history, late rather than never');
        self::assertContains('the next flush', $titles, 'and so is the flush that followed it');
    }

    /**
     * Registers a listener ahead of the audit one, which is what makes it able to stop
     * the audit listener from running at all.
     */
    private function beforeTheAuditListener(object $listener): void
    {
        $ours = array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $registered): bool => $registered instanceof AuditSubscriber,
        ));

        foreach ($ours as $audit) {
            $this->em->getEventManager()->removeEventListener([Events::postFlush], $audit);
        }

        $this->em->getEventManager()->addEventListener([Events::postFlush], $listener);

        foreach ($ours as $audit) {
            $this->em->getEventManager()->addEventListener([Events::postFlush], $audit);
        }
    }

    /**
     * The fate the connection's log gives each statement that starts so, in order.
     *
     * @return list<string>
     */
    private function fatesOf(string $prefix): array
    {
        $fates = [];

        for ($at = 1, $to = $this->statements->position(); $at <= $to; ++$at) {
            $statement = $this->statements->statement($at);

            if ($statement !== null && str_starts_with($statement['sql'], $prefix)) {
                $fates[] = $this->statements->fate($at);
            }
        }

        return $fates;
    }

    /**
     * Registers a listener ahead of the audit one for an event: what a lifecycle callback on
     * the entity always is, and a listener with a higher priority.
     */
    private function aheadOfTheAuditListener(string $event, object $listener): void
    {
        $events = $this->em->getEventManager();
        $there = $events->getListeners($event);

        foreach ($there as $one) {
            $events->removeEventListener([$event], $one);
        }

        $events->addEventListener([$event], $listener);

        foreach ($there as $one) {
            $events->addEventListener([$event], $one);
        }
    }

    /**
     * The parameters of the statement each pending record is tied to, in the order of the
     * records, read just before the audit listener publishes them -- by a postFlush listener
     * ahead of it. What a record is tied to is otherwise gone by the time a test can look:
     * how many documents stand does not say whether two records leaned on one statement.
     *
     * @return \ArrayObject<int, array{record: array<string, mixed>, statement: list<mixed>|null, tied: list<string>}>
     */
    private function theExecutionsTheRecordsAreTiedTo(): \ArrayObject
    {
        $bound = new \ArrayObject();
        $audit = $this->listeners()[0];
        $statements = $this->statements;

        $this->beforeTheAuditListener(new class($bound, $audit, $statements) {
            public function __construct(private readonly \ArrayObject $bound, private readonly AuditSubscriber $audit, private readonly \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog $statements)
            {
            }

            public function postFlush(): void
            {
                $pendingAt = (new \ReflectionProperty(AuditSubscriber::class, 'pendingAt'))->getValue($this->audit);
                $pending = (new \ReflectionProperty(AuditSubscriber::class, 'pending'))->getValue($this->audit);
                $this->bound->exchangeArray([]); // the outermost postFlush is the last, and the one that publishes

                foreach ($pendingAt as $index => $at) {
                    $statement = $at[0] ?? null; // the first table's -- an article has one
                    $this->bound[] = [
                        'record' => array_map(static fn (\Borsche\ElasticsearchAuditBundle\Model\Change $change): mixed => $change->new, $pending[$index]->changes),
                        'statement' => $statement === null ? null : array_values($this->statements->statement($statement)['params'] ?? []),
                        'tied' => array_map(function (int $at): string {
                            $shape = \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape::read($this->statements->statement($at)['sql'] ?? '');

                            return $shape === null ? '?' : $shape->kind.' '.$shape->table;
                        }, $at ?? []),
                    ];
                }
            }
        });

        return $bound;
    }

    private function detachedListener(): AuditSubscriber
    {
        return new AuditSubscriber($this->writer(FailurePolicy::Log), new AuditMetadataFactory(), $this->statements, skipEmptyUpdates: true);
    }

    private function persisted(Article $article): Article
    {
        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    /**
     * @return list<object>
     */
    private function listeners(): array
    {
        return array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof \Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber,
        ));
    }
}
