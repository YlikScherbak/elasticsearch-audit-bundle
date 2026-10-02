<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation\ShadowHistory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the history says when a flush is started from inside another, measured against the rows.
 *
 * These are the acceptance tests of the move to watching the connection. Every one was a
 * probe first, run against the listener as it stands, and each pins two things: the rows,
 * which are the truth, and the whole ordered list of facts the history should hold -- every
 * change with both of its sides, and nothing that did not happen. A comparison that only
 * looks for the right fact is green over a phantom beside it.
 *
 * **What is wrong today is written down, and checked both ways.** A scenario the listener
 * does not describe yet carries what it says instead. The test fails if that changes -- a
 * defect replaced by a different one under the same name is not covered by the entry -- and
 * it fails if the listener starts telling the truth, until the entry is taken off. So the
 * list can only shrink by being fixed, and nothing fixed stays listed.
 *
 * Three things the probes established that every scenario here leans on:
 *
 *   - A flush nested inside another carries out what the outer flush had scheduled and not
 *     yet written. When control comes back, the outer flush has announcements left and no
 *     statements: postUpdate for a line the nested flush already wrote, with an empty change
 *     set.
 *   - The change set this listener sees in postUpdate is the one left after every listener
 *     registered before it -- including an entity's own lifecycle callbacks, which Doctrine
 *     calls before any listener -- so a nested flush started there empties it before this
 *     listener reads it. That is why most scenarios run in both orders.
 *   - UnitOfWork::commit() closes the manager BEFORE it rolls back, and close() clears
 *     before it marks itself closed. A nested flush that dies raises onClear while the outer
 *     transaction is alive and will commit.
 *
 * Facts are compared as the documents list them, in the order they were written. That order
 * is not a promise the reader makes; if a change to how records are grouped moves it, the
 * expectation is rewritten with the reason, not sorted into agreement.
 */
final class WhatANestedFlushLeavesOfTheOuterOneTest extends DoctrineTestCase
{
    /** What the connection did; the shadow history is built from it. */
    private StatementLog $log;

    /** Where the scenario begins in it, once its fixture is written. */
    private int $from = 0;

    /** @var array<class-string, array<string, array<string, mixed>>> the rows, read when the scenario began */
    private array $before = [];

    private RowMemory $remembered;

    /** @var array<class-string, list<object>> every entity postPersist announced, in order */
    private array $persisted = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Without savepoints on DBAL 3 (nested_flush_provenance: outer), where a nested
        // flush cannot be told apart on the wire: what is held to the truth here is the
        // facts, and they are the same either way -- who signed them is WhoWroteItTest's.
        $this->log = $this->watchTheConnection(FailurePolicy::Throw, savepoints: false);
    }

    public function testAChangeTheOuterFlushWroteAndTheNestedOneChangedAgainIsTwoFacts(): void
    {
        // S1a. The outer flush wrote X 1 -> 2; the nested one wrote X 2 -> 5, and Y, which
        // the outer flush had scheduled. Two UPDATEs of one column are two facts.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($x): void {
            $x->quantity = 5;
            $this->em->flush();
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory([1 => 5, 2 => 2], $this->theOuterFlushsYAndTheNestedFlushsSecondX());
    }

    public function testALineTheNestedFlushWroteForTheOuterOneHasTheValueItWrote(): void
    {
        // S1b. The outer flush scheduled Y 1 -> 2; the nested one set 7 and wrote the line
        // with Doctrine's merged change set. The row holds 7.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $y->quantity = 7;
            $this->em->flush();
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory(
            [1 => 2, 2 => 7],
            ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.2.quantity: 1 -> 7'],
        );
    }

    public function testAChangeWrittenBeforeANestedFlushStartedAheadOfThisListenerIsKept(): void
    {
        // S1e. S1a, with the nested flush started from a listener registered BEFORE this
        // one: by the time this listener reads X's postUpdate, the nested flush's cleanup
        // has emptied the change set of a statement that really ran.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($x): void {
            $x->quantity = 5;
            $this->em->flush();
        }, aheadOfThisListener: true);

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory([1 => 5, 2 => 2], $this->theOuterFlushsYAndTheNestedFlushsSecondX());
    }

    public function testANestedFlushRefusedAheadOfThisListenerDoesNotLendItsChangeSet(): void
    {
        // The third form of S1e: not a change set emptied, but one filled. The nested flush
        // sets X = 5, computes its change set and is refused in onFlush; the set is left
        // behind, and the outer flush's postUpdate for X -- read after it -- finds 2 -> 5
        // where the statement that ran wrote 1 -> 2.
        [, $x] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($x): void {
            $x->quantity = 5;
            $this->refused(fn () => $this->em->flush());
        }, aheadOfThisListener: true);

        $x->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory(
            [1 => 2, 2 => 1],
            ['crate C-1 items.1.quantity: 1 -> 2'],
        );
    }

    public function testAChangeAfterARefusedFlushNamesWhatTheRowHeld(): void
    {
        // Not nested: the case that tells the two sources of the shadow history's starting
        // rows apart. A flush refused in onFlush leaves Doctrine remembering X as 5, a value
        // the row never took; the next flush writes 2. The row went 1 -> 2. What Doctrine
        // remembers at that flush's preFlush is 5 -- which is why the copy that counts is the
        // first one taken, before the refusal, and why a listener that took its old sides from
        // Doctrine's memory afresh at every operation would get this wrong.
        [, $x] = $this->aCrateWithTwoLines();

        $x->quantity = 5;
        $this->refused(fn () => $this->em->flush());

        $x->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory([1 => 2, 2 => 1], ['crate C-1 items.1.quantity: 1 -> 2']);
    }

    public function testAnEmptyingAfterTheOuterFlushWroteALineKeepsWhatItWrote(): void
    {
        // S2. The outer flush wrote X 1 -> 2; the nested one emptied the crate, and then
        // ran the outer flush's leftover UPDATE of Y, which reached no row.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $crate = $x->crate;
        $this->inThePostUpdateOf($x, function () use ($crate): void {
            $crate->items = new ArrayCollection();
            $this->em->flush();
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory(
            [],
            ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items: ["SKU-X","SKU-Y"] -> []'],
        );
    }

    public function testALineTheNestedFlushDeletedWasNeverUpdated(): void
    {
        // S2b. The outer flush scheduled Y 1 -> 2; the nested one removed Y, which took it
        // off the updates. The row went with 1 in it.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $this->em->remove($y);
            $this->em->flush();
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory(
            [1 => 2],
            ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.2: "SKU-Y" -> null'],
        );
    }

    public function testALineInsertedByTheOuterFlushAndEmptiedByTheNestedOneCameAndWent(): void
    {
        // S3. Both happened: the INSERT reached the table and the DELETE took the row.
        [$crate] = $this->aCrateWithTwoLines();

        $crate->add($n = new CrateItem('SKU-N'));
        $this->inThe(Events::postPersist, $n, function () use ($crate): void {
            $crate->items = new ArrayCollection();
            $this->em->flush();
        });

        $this->em->flush();

        $this->assertTheRowsAndTheHistory(
            [],
            ['crate C-1 items.3: null -> "SKU-N"', 'crate C-1 items: ["SKU-X","SKU-Y","SKU-N"] -> []'],
        );
    }

    #[DataProvider('bothOrders')]
    public function testANestedFlushThatDiesLeavesTheOuterFlushsHistory(bool $ahead): void
    {
        // 6 / A. The nested flush writes Y 1 -> 7 and dies; Doctrine rolls back to its
        // savepoint, the application catches it, and the outer flush commits X 1 -> 2.
        //
        // The death goes through close(), whose clear() raises onClear while the outer
        // flush is alive: what the manager forgets there is Doctrine's, and what ran is the
        // log's to say -- the savepoint took Y back, and X stays. Not the same as the
        // application rolling back a nested flush itself, which raises no onClear; hence the
        // premise below, that this one did. And after it the application goes on with a new
        // manager: X is not written twice, and Y does not come back.
        [, $x, $y] = $this->aCrateWithTwoLines();
        $cleared = $this->theClearsWhileOpen();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $y->quantity = 7;
            $this->dying(fn () => $this->em->flush(), $y);
        }, aheadOfThisListener: $ahead);

        $x->quantity = 2;
        $this->theOuterFlushAfterANestedOneDied();

        self::assertContains(true, $cleared->getArrayCopy(), 'the premise: the death cleared the manager while it was still open');

        $this->theNextOperationWritesItselfAndNothingElse();
    }

    #[DataProvider('bothOrders')]
    public function testANestedFlushThatDiesAfterWritingTheOuterFlushsOwnRowLeavesTheOuterChange(bool $ahead): void
    {
        // 6 / A on one row: the outer flush writes X 1 -> 2, the nested flush started from
        // X's postUpdate writes X 2 -> 5 and dies. Two executions of the same row; the
        // savepoint takes the second back and the outer flush commits the first.
        [, $x] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($x): void {
            $x->quantity = 5;
            $this->dying(fn () => $this->em->flush(), $x);
        }, aheadOfThisListener: $ahead);

        $x->quantity = 2;
        $this->theOuterFlushAfterANestedOneDied();
    }

    public function testAStatementTheApplicationTookBackInsideAFlushIsNotAFactAndTheFlushsOthersAre(): void
    {
        // One flush, two statements, and a savepoint of the application's between them: it
        // opens one in X's postUpdate and rolls back to it in Y's. The flush commits with X
        // written and Y taken back -- so whether a record stands is decided by the statements
        // it was built from, not by whether its flush ran anything at all.
        $this->log = $this->watchTheConnection(FailurePolicy::Throw);
        [, $x, $y] = $this->aCrateWithTwoLines();
        $connection = $this->em->getConnection();

        $this->inThePostUpdateOf($x, static fn () => $connection->beginTransaction());
        $this->inThePostUpdateOf($y, static fn () => $connection->rollBack());

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory([1 => 2, 2 => 1], ['crate C-1 items.1.quantity: 1 -> 2']);
    }

    public function testALineChangedAndThenRemovedByTheSameFlushIsBothFacts(): void
    {
        // Not nested. Doctrine never plans an UPDATE and a DELETE of one entity in one
        // flush -- scheduling a removal takes it off the updates -- but a listener that
        // removes it after its UPDATE ran gets both: executeDeletions() reads the live list.
        // Two statements reached the row, so two facts, in the order they ran.
        [, $x] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($x): void {
            $this->em->remove($x);
        });

        $x->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory(
            [2 => 1],
            ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.1: "SKU-X" -> null'],
        );
    }

    public function testAFlushInsideTheApplicationsTransactionKeepsItsHistory(): void
    {
        // Not nested in another flush, nested in the application's transaction. Without
        // savepoints (DBAL 3, nested_flush_provenance: outer) the flush's own beginTransaction()
        // opens nothing on the wire, so there is no frame for it to claim: its statements are
        // still its own, because they ran while it did.
        [, $x] = $this->aCrateWithTwoLines();
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $x->quantity = 2;
        $this->em->flush();
        $connection->commit();

        $this->assertTheRowsAndTheHistory([1 => 2, 2 => 1], ['crate C-1 items.1.quantity: 1 -> 2']);
    }

    public function testWhatAFlushWritesAfterANestedOneIsNotFiledBackBeforeIt(): void
    {
        // The crate's own change is the outer flush's first statement and its first record.
        // X's preUpdate then starts a nested flush, which carries out X and Y as they stand,
        // and then sets X again, which the outer flush writes -- its own statement, after the
        // nested one's. Filed back into the outer flush's first record, the history would list
        // it before the statements that ran first. (A nested flush carries out everything the
        // outer one had left, so a later statement of the outer flush's own has to be a change
        // made after it.) With savepoints: without them the nested flush's are the outer one's.
        $this->log = $this->watchTheConnection(FailurePolicy::Throw, savepoints: true);
        [$crate, $x, $y] = $this->aCrateWithTwoLines();

        $this->em->getEventManager()->addEventListener([Events::preUpdate], new class($this->em, $x, $y) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly object $x, private readonly CrateItem $y)
            {
            }

            public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
            {
                if ($this->ran || $args->getObject() !== $this->x) {
                    return;
                }

                $this->ran = true;
                $this->y->quantity = 7;
                $this->em->flush();
                $this->x->quantity = 3;
            }
        });

        $crate->status = 'checked';
        $x->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory(
            [1 => 3, 2 => 7],
            ['crate C-1 status: "packed" -> "checked"', 'crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.2.quantity: 1 -> 7', 'crate C-1 items.1.quantity: 2 -> 3'],
        );
    }

    public function testALineChangedAndRemovedByAFlushThatDiesIsNeither(): void
    {
        // The same two statements, and the flush dies after both: neither happened.
        [, $x] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($x): void {
            $this->em->remove($x);
        });
        $this->em->getEventManager()->addEventListener([Events::postRemove], new class {
            public function postRemove(): void
            {
                throw new \DomainException('the flush dies after its DELETE');
            }
        });

        $x->quantity = 2;
        $this->failing(fn () => $this->em->flush());
        $this->andTheApplicationGoesOn();

        $this->assertTheRowsAndTheHistory([1 => 1, 2 => 1], []);
    }

    public function testAnOuterFlushThatDiesLeavesNothing(): void
    {
        // B. Both statements ran; the transaction rolled back.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($y, static function (): void {
            throw new \DomainException('the outer flush dies after its statements');
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->failing(fn () => $this->em->flush());
        $this->andTheApplicationGoesOn();

        $this->assertTheRowsAndTheHistory([1 => 1, 2 => 1], []);
    }

    public function testAnOuterFlushThatDiesAfterASuccessfulNestedOneLeavesNothing(): void
    {
        // E. The nested flush finished and left the stack; its share is still held when the
        // outer flush dies, and it goes with it.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $done = new \ArrayObject(['nested' => false]);
        $this->inThePostUpdateOf($x, function () use ($x, $done): void {
            $x->quantity = 5;
            $this->em->flush();
            $done['nested'] = true;
        });
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($y, $done) {
            public function __construct(private readonly object $y, private readonly \ArrayObject $done)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                if ($this->done['nested'] && $args->getObject() === $this->y) {
                    throw new \DomainException('the outer flush dies after the nested one succeeded');
                }
            }
        });

        $cleared = $this->theClearsWhileOpen();
        $x->quantity = 2;
        $y->quantity = 2;
        $this->failing(fn () => $this->em->flush());

        // What takes both halves back is the transaction's ROLLBACK, in the log -- the
        // clear on the way out forgets Doctrine's objects and decides nothing.
        self::assertContains(true, $cleared->getArrayCopy(), 'the premise: the death cleared the manager while it was still open');

        $this->andTheApplicationGoesOn();

        $this->assertTheRowsAndTheHistory([1 => 1, 2 => 1], []);
    }

    #[DataProvider('bothOrders')]
    public function testAClearInTheMiddleOfAFlushKeepsWhatWasWrittenAndNotWhatItCancelled(bool $ahead): void
    {
        // F. A listener clears the manager in X's postUpdate. X was written; Y's UPDATE never
        // runs, because clear() emptied every change set -- and Doctrine announces
        // postUpdate for Y all the same.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function (): void {
            $this->em->clear();
        }, aheadOfThisListener: $ahead);

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory([1 => 2, 2 => 1], ['crate C-1 items.1.quantity: 1 -> 2']);
    }

    public function testAClearByTheApplicationThenANestedFlushThatDiesKeepsTheOuterFlushsHistory(): void
    {
        // G. The application clears in the outer flush -- which is alive -- and then a
        // nested flush dies. The first onClear is not a death; the second is, and only of
        // the nested flush.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $this->em->clear();
            $again = $this->em->find(CrateItem::class, $y->id);
            self::assertInstanceOf(CrateItem::class, $again);
            $again->quantity = 9;
            $this->dying(fn () => $this->em->flush(), $again);
        });

        $x->quantity = 2;
        $this->theOuterFlushAfterANestedOneDied();
    }

    public function testARemovalScheduledAfterAClearIsCarriedOutAndRecorded(): void
    {
        // H. After the clear, a listener loads Y afresh and removes it without flushing:
        // the outer flush's deletions read the live schedule and carry it out. Y's old
        // UPDATE was cancelled by the clear and is not a fact; its DELETE is.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $this->em->clear();
            $again = $this->em->find(CrateItem::class, $y->id);
            self::assertInstanceOf(CrateItem::class, $again);
            $this->em->remove($again);
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        $this->assertTheRowsAndTheHistory([1 => 2], ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.2: "SKU-Y" -> null']);
    }

    public function testAnOnClearListenerThatClearsAgainDoesNotMakeADeathLookLikeAClear(): void
    {
        // I. close() clears, and a listener ahead of this one clears again from inside that
        // onClear: this listener is told twice, both times with the manager still open.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->ahead(Events::onClear, new class {
            private bool $inside = false;

            public function onClear(\Doctrine\ORM\Event\OnClearEventArgs $args): void
            {
                if (!$this->inside) {
                    $this->inside = true;
                    $args->getObjectManager()->clear();
                    $this->inside = false;
                }
            }
        });
        $this->inThePostUpdateOf($y, static function (): void {
            throw new \DomainException('the outer flush dies after its statements');
        });

        $x->quantity = 2;
        $y->quantity = 2;
        $this->failing(fn () => $this->em->flush());
        $this->andTheApplicationGoesOn();

        $this->assertTheRowsAndTheHistory([1 => 1, 2 => 1], []);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function bothOrders(): iterable
    {
        yield 'nested from a listener behind this one' => [false];
        yield 'nested from a listener ahead of this one' => [true];
    }

    /**
     * @return array{Crate, CrateItem, CrateItem, CrateItem|null}
     */
    private function aCrateWithTwoLines(bool $andAThird = false): array
    {
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($x = new CrateItem('SKU-X'));
        $crate->add($y = new CrateItem('SKU-Y'));
        $z = null;

        if ($andAThird) {
            $crate->add($z = new CrateItem('SKU-Z'));
        }

        $this->em->flush();

        $this->gateway->documents = [];
        $this->gateway->ids = [];

        // The scenario begins here, and so does what the shadow history replays: the rows as
        // they are now, and a copy of what Doctrine remembers at every preFlush and postLoad
        // from here on.
        $this->from = $this->log->position();
        $this->before = ShadowHistory::theRows($this->em, [Crate::class, CrateItem::class]);
        $this->remembered = new RowMemory($this->log);
        $this->em->getEventManager()->addEventListener([Events::preFlush, Events::postLoad], new class($this->remembered) {
            public function __construct(private readonly RowMemory $memory)
            {
            }

            public function preFlush(\Doctrine\ORM\Event\PreFlushEventArgs $args): void
            {
                $this->memory->rememberWhatIsManaged($args->getObjectManager());
            }

            public function postLoad(\Doctrine\ORM\Event\PostLoadEventArgs $args): void
            {
                $em = $args->getObjectManager();

                if ($em instanceof \Doctrine\ORM\EntityManagerInterface) {
                    // What the listener will know from its stack; the tests ask the connection.
                    $this->memory->rememberLoaded($em, $args->getObject(), $em->getConnection()->isTransactionActive());
                }
            }
        });
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class($this->persisted) {
            /** @param array<class-string, list<object>> $persisted */
            public function __construct(private array &$persisted)
            {
            }

            public function postPersist(PostPersistEventArgs $args): void
            {
                $entity = $args->getObject();
                $this->persisted[$args->getObjectManager()->getClassMetadata($entity::class)->rootEntityName][] = $entity;
            }
        });

        return [$crate, $x, $y, $z];
    }

    /**
     * @param array<int, int> $rows        quantity by id, and every row there is
     * @param list<string>    $facts       the whole history, in the order it was written
     * @param list<string>|null $today     what the listener says instead, while it does not describe this yet
     */
    private function assertTheRowsAndTheHistory(array $rows, array $facts, ?array $today = null): void
    {
        $inTheTable = [];

        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT id, quantity FROM CrateItem ORDER BY id') as $row) {
            $inTheTable[(int) $row['id']] = (int) $row['quantity'];
        }

        self::assertSame($rows, $inTheTable, 'the premise: what the rows hold');

        $this->assertTheShadowHistorySays($facts);

        $said = $this->everyFact();

        if ($today === null) {
            self::assertSame($facts, $said, 'the history says exactly what the rows did');

            return;
        }

        self::assertNotSame($facts, $said, 'this is described correctly now; take its entry for today off');
        self::assertSame($today, $said, 'what the listener says instead has changed; the entry no longer describes it');
    }

    /**
     * What the connection's log gives, from each of the two places the rows it starts from can
     * come from, against the truth -- and nothing it was unsure of.
     *
     * Compared without order: in what order the history lists the facts of a flush is the
     * grouping's, which this does not decide, and ORM 2 and 3 run a nested flush's statements
     * in different orders.
     *
     * @param list<string> $facts
     */
    private function assertTheShadowHistorySays(array $facts): void
    {
        $expected = $facts;
        sort($expected);

        foreach ([
            'from the rows read before the scenario' => [$this->before, []],
            'from the row memory the listener will read' => [$this->remembered->rows(), $this->remembered->takenAt()],
        ] as $source => [$rows, $copiedAt]) {
            $shadow = ShadowHistory::fromWhatWasRemembered($this->em, $rows, $copiedAt)->replay($this->log, $this->from, $this->persisted);
            $said = $shadow['facts'];
            sort($said);

            self::assertSame([], $shadow['unsure'], 'the shadow history, '.$source.', was unsure; it started from '.json_encode($rows));
            self::assertSame($expected, $said, 'the shadow history, '.$source);
        }
    }

    /**
     * Every change in every document, the documents in the order they were written, as
     * "<type> <id> <field>: <old> -> <new>".
     *
     * Inside one document the changes are sorted: a record is a map of fields, and in what
     * order its keys were filled in is nothing the history promises -- ORM 2 and 3 run a
     * nested flush's statements in different orders. Which record comes first is kept as
     * written, and so is every transition of one field, which are separate records.
     *
     * A field whose two sides are the same is left out: that is an always-recorded field
     * carried for context, not something that happened.
     *
     * @return list<string>
     */
    private function everyFact(): array
    {
        $facts = [];

        foreach ($this->documents() as $document) {
            $said = [];

            foreach ($document['changes'] ?? [] as $field => $change) {
                if (($change['old'] ?? null) === ($change['new'] ?? null)) {
                    continue;
                }

                $said[] = sprintf(
                    '%s %s %s: %s -> %s',
                    (string) ($document['objectType'] ?? '?'),
                    (string) ($document['objectId'] ?? '?'),
                    (string) $field,
                    json_encode($change['old'] ?? null),
                    json_encode($change['new'] ?? null),
                );
            }

            sort($said);
            $facts = [...$facts, ...$said];
        }

        return $facts;
    }

    /**
     * The outer flush of 6/A and G, whose nested flush died, and what the rows and the
     * history are then -- which depends on what the connection does with a nested rollback.
     *
     * With savepoints, which DBAL 4 always uses, the nested flush is rolled back to its
     * savepoint and the outer one commits X 1 -> 2. Without them -- DBAL 3's default -- a
     * nested rollBack() marks the whole transaction rollback-only, the outer commit() is
     * refused, and nothing happened at all: the history must say so, including after the
     * application goes on with another flush.
     */
    private function theOuterFlushAfterANestedOneDied(): void
    {
        $connection = $this->em->getConnection();

        if (method_exists($connection, 'getNestTransactionsWithSavepoints') && !$connection->getNestTransactionsWithSavepoints()) {
            try {
                $this->em->flush();
                self::fail('the premise: without savepoints the outer commit is refused');
            } catch (\Throwable $e) {
                // ORM 3 wraps the refusal in an OptimisticLockException; ORM 2 lets the
                // connection's own exception through. Either way it is the connection
                // refusing a transaction marked rollback-only.
                for ($cause = $e; $cause !== null && !$cause instanceof \Doctrine\DBAL\ConnectionException; $cause = $cause->getPrevious()) {
                }

                self::assertNotNull($cause, 'the premise: refused as rollback-only, not '.$e::class.': '.$e->getMessage());
            }

            $this->andTheApplicationGoesOn();
            $this->assertTheRowsAndTheHistory([1 => 1, 2 => 1], []);

            return;
        }

        $this->em->flush();
        $this->assertTheRowsAndTheHistory([1 => 2, 2 => 1], ['crate C-1 items.1.quantity: 1 -> 2']);
    }

    /**
     * What an application does after a flush that died: a fresh manager, and another flush.
     *
     * Without it a scenario whose flush dies can never show a record that should have gone
     * with it -- nothing comes afterwards to publish it -- and the check that nothing was
     * written passes whatever the listener kept.
     */
    /**
     * Whether each clear the manager raised from here on found it open, in order.
     *
     * @return \ArrayObject<int, bool>
     */
    private function theClearsWhileOpen(): \ArrayObject
    {
        $cleared = new \ArrayObject();
        $this->em->getEventManager()->addEventListener([Events::onClear], new class($cleared) {
            public function __construct(private readonly \ArrayObject $cleared)
            {
            }

            public function onClear(\Doctrine\ORM\Event\OnClearEventArgs $args): void
            {
                $this->cleared[] = $args->getObjectManager()->isOpen();
            }
        });

        return $cleared;
    }

    /**
     * A new manager and an operation of its own, after whatever the scenario left: every
     * document already written stays as it was and is not written again, and the operation
     * adds its own record and nothing else -- neither a rolled-back change coming back nor
     * a published one twice. Nothing is taken out of the collector to make that so.
     */
    private function theNextOperationWritesItselfAndNothingElse(): void
    {
        $before = $this->gateway->documents['audit_log'] ?? [];

        $this->reopen();
        $this->em->persist(new Crate('C-3'));
        $this->em->flush();

        $after = $this->gateway->documents['audit_log'] ?? [];

        self::assertSame($before, \array_slice($after, 0, \count($before)), 'what was written before stays as it was');
        self::assertSame(
            [['crate', 'C-3']],
            array_map(static fn (array $d): array => [$d['objectType'] ?? null, $d['objectId'] ?? null], \array_slice($after, \count($before))),
            'and the next operation writes itself, and nothing the scenario left',
        );
    }

    private function andTheApplicationGoesOn(): void
    {
        $this->reopen();
        $this->em->persist(new Crate('C-2'));
        $this->em->flush();
        $this->gateway->documents['audit_log'] = array_values(array_filter(
            $this->gateway->documents['audit_log'] ?? [],
            static fn (array $document): bool => ($document['objectId'] ?? null) !== 'C-2',
        ));
    }

    /**
     * Runs something once, inside the postUpdate of one entity -- behind this listener, or
     * ahead of it, which is where a lifecycle callback on the entity always is.
     */
    private function inThePostUpdateOf(object $entity, \Closure $what, bool $aheadOfThisListener = false): void
    {
        $this->inThe(Events::postUpdate, $entity, $what, $aheadOfThisListener);
    }

    private function inThe(string $event, object $entity, \Closure $what, bool $aheadOfThisListener = false): void
    {
        $listener = new class($event, $entity, $what) {
            private bool $ran = false;

            public function __construct(private readonly string $event, private readonly object $entity, private readonly \Closure $what)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                $this->maybe(Events::postUpdate, $args->getObject());
            }

            public function postPersist(PostPersistEventArgs $args): void
            {
                $this->maybe(Events::postPersist, $args->getObject());
            }

            private function maybe(string $event, object $entity): void
            {
                if ($this->ran || $event !== $this->event || $entity !== $this->entity) {
                    return;
                }

                $this->ran = true;
                ($this->what)();
            }
        };

        if ($aheadOfThisListener) {
            $this->ahead($event, $listener);
        } else {
            $this->em->getEventManager()->addEventListener([$event], $listener);
        }
    }

    /**
     * Registers a listener ahead of every one already there, this listener included.
     */
    private function ahead(string $event, object $listener): void
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
     * A flush that dies after its statement, by a listener that throws in the postUpdate of
     * the line it wrote, caught the way an application catches it.
     */
    private function dying(\Closure $flush, object $line): void
    {
        $breaker = new class($line) {
            public function __construct(private readonly object $line)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                if ($args->getObject() === $this->line) {
                    throw new \RuntimeException('the nested flush dies after its statement');
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postUpdate], $breaker);

        try {
            $flush();
            self::fail('the premise: the nested flush died');
        } catch (\RuntimeException) {
            // what an application does about a nested flush that failed
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::postUpdate], $breaker);
        }
    }

    /**
     * A flush refused in onFlush by a listener behind this one, caught.
     */
    private function refused(\Closure $flush): void
    {
        $veto = new class {
            public function onFlush(): void
            {
                throw new \DomainException('refused');
            }
        };

        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        try {
            $flush();
            self::fail('the premise: the flush was refused');
        } catch (\DomainException) {
            // what an application does about a flush a listener refused
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $veto);
        }
    }

    /**
     * S1a's history, record by record -- which follows the order the statements ran in.
     *
     * Without savepoints, as here, all three are the outer flush's, and a record holds a
     * field once: the second write of X starts a new one. On ORM 3 the nested flush writes X
     * 2 -> 5 before Y, so X 1 -> 2 is alone and the second record holds X 2 -> 5 and Y; on
     * ORM 2 it writes Y first, so the first record holds X 1 -> 2 and Y, and X 2 -> 5 opens
     * the second. Measured, not reasoned: the same three facts, grouped as they ran.
     *
     * @return list<string>
     */
    private function theOuterFlushsYAndTheNestedFlushsSecondX(): array
    {
        // Whose statement Y's UPDATE is, is Doctrine's to decide. One ORM dispatches X's postUpdate
        // only after the outer flush has written every row it scheduled; another dispatches it
        // right after X's own row, and the nested flush then runs on the same unit of work, with
        // Y's update still pending -- so the nested flush writes Y, and Y's change is the nested
        // flush's, in a record of its own after the second X. The order of the statements is the
        // same both ways (X, Y, X again); what differs is which flush each belongs to, and that is
        // what the history follows.
        //
        // This used to be told by ORM 2 against ORM 3, and ORM 3.0.0 dispatches the way ORM 2
        // does; so it is read off the log, from the flush each statement belongs to.
        $xSetTo2 = $ySetTo2 = null;

        for ($at = $this->from + 1, $to = $this->log->position(); $at <= $to; ++$at) {
            $statement = $this->log->statement($at);

            if ($statement === null || !str_starts_with($statement['sql'], 'UPDATE CrateItem')) {
                continue;
            }

            $params = array_map('intval', array_values($statement['params']));
            $xSetTo2 ??= $params === [2, 1] ? $at : null;
            $ySetTo2 ??= $params === [2, 2] ? $at : null;
        }

        self::assertNotNull($xSetTo2, 'the premise: the outer flush set X to 2');
        self::assertNotNull($ySetTo2, 'the premise: Y was set to 2');

        return $this->log->ownerOf($ySetTo2) === $this->log->ownerOf($xSetTo2)
            ? ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.2.quantity: 1 -> 2', 'crate C-1 items.1.quantity: 2 -> 5']
            : ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.1.quantity: 2 -> 5', 'crate C-1 items.2.quantity: 1 -> 2'];
    }

    /**
     * A flush the scenario has arranged to die, caught.
     */
    private function failing(\Closure $flush): void
    {
        try {
            $flush();
            self::fail('the premise: the flush died');
        } catch (\DomainException) {
            // what an application does about a flush that failed
        }
    }
}
