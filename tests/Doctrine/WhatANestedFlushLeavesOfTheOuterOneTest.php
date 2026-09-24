<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation\ShadowHistory;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation\WhatDoctrineRemembered;
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

    private WhatDoctrineRemembered $remembered;

    /** @var array<class-string, list<object>> every entity postPersist announced, in order */
    private array $persisted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = $this->watchTheConnection(FailurePolicy::Throw);
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

        $this->assertTheRowsAndTheHistory(
            [1 => 5, 2 => 2],
            ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.1.quantity: 2 -> 5', 'crate C-1 items.2.quantity: 1 -> 2'],
            today: ['crate C-1 items.1.quantity: 2 -> 5', 'crate C-1 items.2.quantity: 1 -> 2'],
        );
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
            today: ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.2.quantity: 1 -> 2'],
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

        $this->assertTheRowsAndTheHistory(
            [1 => 5, 2 => 2],
            ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.1.quantity: 2 -> 5', 'crate C-1 items.2.quantity: 1 -> 2'],
            today: ['crate C-1 items.2.quantity: 1 -> 2', 'crate C-1 items.1.quantity: 1 -> 2'],
        );
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
            today: ['crate C-1 items.1.quantity: 1 -> 5'],
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
            today: ['crate C-1 items: ["SKU-X","SKU-Y"] -> []'],
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
            today: ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.2.quantity: 1 -> 2', 'crate C-1 items.2: "SKU-Y" -> null'],
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
            today: ['crate C-1 items: ["SKU-X","SKU-Y"] -> []'],
        );
    }

    #[DataProvider('bothOrders')]
    public function testANestedFlushThatDiesLeavesTheOuterFlushsHistory(bool $ahead): void
    {
        // 6 / A. The nested flush writes Y 1 -> 7 and dies; Doctrine rolls back to its
        // savepoint, the application catches it, and the outer flush commits X 1 -> 2.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $y->quantity = 7;
            $this->dying(fn () => $this->em->flush(), $y);
        }, aheadOfThisListener: $ahead);

        $x->quantity = 2;
        $this->theOuterFlushAfterANestedOneDied();
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

        $x->quantity = 2;
        $y->quantity = 2;
        $this->failing(fn () => $this->em->flush());
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

        $this->assertTheRowsAndTheHistory([1 => 2, 2 => 1], ['crate C-1 items.1.quantity: 1 -> 2'], today: []);
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

        $this->assertTheRowsAndTheHistory([1 => 2], ['crate C-1 items.1.quantity: 1 -> 2', 'crate C-1 items.2: "SKU-Y" -> null'], today: []);
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
     * @return array{Crate, CrateItem, CrateItem}
     */
    private function aCrateWithTwoLines(): array
    {
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($x = new CrateItem('SKU-X'));
        $crate->add($y = new CrateItem('SKU-Y'));
        $this->em->flush();

        $this->gateway->documents = [];
        $this->gateway->ids = [];

        // The scenario begins here, and so does what the shadow history replays: the rows as
        // they are now, and a copy of what Doctrine remembers at every preFlush and postLoad
        // from here on.
        $this->from = $this->log->position();
        $this->before = (new \ReflectionProperty(ShadowHistory::class, 'rows'))->getValue(ShadowHistory::fromTheRows($this->em, [Crate::class, CrateItem::class]));
        $this->remembered = new WhatDoctrineRemembered([Crate::class, CrateItem::class], $this->log);
        $this->em->getEventManager()->addEventListener([Events::preFlush, Events::postLoad], $this->remembered);
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

        return [$crate, $x, $y];
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
            'from what Doctrine remembered at preFlush and postLoad' => [$this->remembered->rows, $this->remembered->copiedAt],
        ] as $source => [$rows, $copiedAt]) {
            $shadow = ShadowHistory::fromWhatWasRemembered($this->em, $rows, $copiedAt)->replay($this->log, $this->from, $this->persisted);
            $said = $shadow['facts'];
            sort($said);

            self::assertSame([], $shadow['unsure'], 'the shadow history, '.$source.', was unsure; it started from '.json_encode($rows));
            self::assertSame($expected, $said, 'the shadow history, '.$source);
        }
    }

    /**
     * Every change in every document, in order, as "<type> <id> <field>: <old> -> <new>".
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
            foreach ($document['changes'] ?? [] as $field => $change) {
                if (($change['old'] ?? null) === ($change['new'] ?? null)) {
                    continue;
                }

                $facts[] = sprintf(
                    '%s %s %s: %s -> %s',
                    (string) ($document['objectType'] ?? '?'),
                    (string) ($document['objectId'] ?? '?'),
                    (string) $field,
                    json_encode($change['old'] ?? null),
                    json_encode($change['new'] ?? null),
                );
            }
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
        $this->assertTheRowsAndTheHistory([1 => 2, 2 => 1], ['crate C-1 items.1.quantity: 1 -> 2'], today: []);
    }

    /**
     * What an application does after a flush that died: a fresh manager, and another flush.
     *
     * Without it a scenario whose flush dies can never show a record that should have gone
     * with it -- nothing comes afterwards to publish it -- and the check that nothing was
     * written passes whatever the listener kept.
     */
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
