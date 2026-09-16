<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
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
        $line = new LedgerLine('jan', 'January');
        $line->unreadable = true;
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
     * ORM 2 only: clearing one entity class while a flush is running leaves that flush
     * to commit the rest, so its records are history and must survive the clear.
     *
     * Driven by hand, because the point is a clear arriving between the lifecycle events
     * and postFlush — a window a normal flush does not expose.
     */
    public function testAPartialClearKeepsTheRecordsOfAFlushThatIsStillRunning(): void
    {
        self::skipUnlessPartialClearsExist();

        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $listener = $this->detachedListener();
        $listener->postPersist(new PostPersistEventArgs($article, $this->em));
        $listener->onClear(new OnClearEventArgs($this->em, Article::class)); // @phpstan-ignore-line ORM 2 signature
        $listener->postFlush(new PostFlushEventArgs($this->em));

        self::assertCount(1, $this->documents(), 'the other classes in that flush still committed');
    }

    /**
     * ORM 2 only: a closed manager means the flush failed. A partial clear does not make
     * its records true, and inventing history is worse than missing it.
     */
    public function testAPartialClearOnAClosedManagerStillDropsThem(): void
    {
        self::skipUnlessPartialClearsExist();

        $article = $this->persisted(new Article('Hello'));
        $this->gateway->documents = [];

        $listener = $this->detachedListener();
        $listener->postPersist(new PostPersistEventArgs($article, $this->em));
        $this->em->close();
        $listener->onClear(new OnClearEventArgs($this->em, Article::class)); // @phpstan-ignore-line ORM 2 signature
        $listener->postFlush(new PostFlushEventArgs($this->em));

        self::assertSame([], $this->documents());
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

    private function detachedListener(): AuditSubscriber
    {
        return new AuditSubscriber($this->writer(FailurePolicy::Log), new AuditMetadataFactory(), skipEmptyUpdates: true);
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
