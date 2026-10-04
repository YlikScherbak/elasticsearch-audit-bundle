<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Ledger;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\LedgerLine;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Rack;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\RackNote;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vehicle;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * What the row memory holds, and when -- the source the listener will take its old sides from.
 *
 * It is fed here as the listener will feed it: everything managed at preFlush, a row loaded at
 * postLoad only while a flush runs. Each test is a case two reviewers named for it before it was
 * written: a change made before the first preFlush, a flush refused and tried again, a column
 * written twice before the commit and a rollback in between, a row loaded again after a commit,
 * what is forgotten and when, and what it costs.
 */
final class RowMemoryTest extends DoctrineTestCase
{
    private StatementLog $log;

    private RowMemory $memory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = $this->watchTheConnection();
        $this->memory = new RowMemory($this->log);

        $this->em->getEventManager()->addEventListener([Events::preFlush, Events::postLoad], new class($this->memory) {
            public function __construct(private readonly RowMemory $memory)
            {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                $this->memory->rememberWhatIsManaged($args->getObjectManager());
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                $em = $args->getObjectManager();

                if ($em instanceof \Doctrine\ORM\EntityManagerInterface) {
                    $this->memory->rememberLoaded($em, $args->getObject(), $em->getConnection()->isTransactionActive());
                }
            }
        });
    }

    public function testWhatARowHeldIsRememberedAfterTheObjectHasMovedOn(): void
    {
        $x = $this->aLine();

        $x->quantity = 2; // before the preFlush that remembers it
        $this->em->flush();

        self::assertSame(1, (int) ($this->memory->rows()[CrateItem::class][(string) $x->id]['quantity'] ?? 0), 'the row, not the object');
        self::assertSame(['crate C-1 items.'.$x->id.'.quantity: 1 -> 2'], $this->facts());
    }

    public function testAFlushRefusedAndTriedAgainDoesNotTeachThePlanAsTheRow(): void
    {
        $x = $this->aLine();

        $x->quantity = 5;
        $this->refused();
        $x->quantity = 2;
        $this->em->flush();

        self::assertSame(['crate C-1 items.'.$x->id.'.quantity: 1 -> 2'], $this->facts(), 'Doctrine remembered 5 at the second preFlush; the row never held it');
    }

    public function testAColumnWrittenTwiceBeforeTheCommitIsReadFromWhereTheFirstWriteLeftIt(): void
    {
        // S1a before its commit: after the outer UPDATE the row holds 2, after the nested one
        // 5 -- read inside the transaction, before anything is final.
        $x = $this->aLine();
        $seen = [];

        $this->inThePostUpdateOf($x, function () use ($x, &$seen): void {
            $seen[] = $this->now($x);
            $x->quantity = 5;
            $this->em->flush();
            $seen[] = $this->now($x);
        });

        $x->quantity = 2;
        $this->em->flush();

        self::assertSame([2, 5], $seen);
        self::assertSame(['crate C-1 items.'.$x->id.'.quantity: 1 -> 2', 'crate C-1 items.'.$x->id.'.quantity: 2 -> 5'], $this->facts());
    }

    public function testANestedRollbackTakesTheRowBackAndAFullOneTakesItToTheStart(): void
    {
        $x = $this->aLine();
        $y = new CrateItem('SKU-Y');
        $x->crate?->add($y);
        $this->em->flush();
        $this->memory->settle($this->em);

        $seen = [];

        $this->inThePostUpdateOf($x, function () use ($x, $y, &$seen): void {
            $y->quantity = 7;
            $this->dying(fn () => $this->em->flush(), $y);
            $seen[] = ['x' => $this->now($x), 'y' => $this->now($y)];
        });

        $x->quantity = 2;
        $savepoints = !method_exists($this->em->getConnection(), 'getNestTransactionsWithSavepoints') || $this->em->getConnection()->getNestTransactionsWithSavepoints();

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            self::assertFalse($savepoints, 'the premise: only a rollback-only transaction refuses the commit -- '.$e->getMessage());
        }

        // With savepoints the nested flush's 7 is rolled back to its savepoint and X's 2 stays;
        // without, the nested rollback is not seen until the whole transaction goes.
        self::assertSame($savepoints ? [['x' => 2, 'y' => 1]] : [['x' => 2, 'y' => 7]], $seen, 'inside the transaction');
        self::assertSame($savepoints ? 2 : 1, $this->now($x), 'and after it');
    }

    public function testARowLoadedAgainAfterACommitStartsFromWhatWasCommitted(): void
    {
        $x = $this->aLine();
        $id = $x->id;

        $x->quantity = 2;
        $this->em->flush();
        self::assertTrue($this->memory->settle($this->em));
        $this->log->forgetUpTo($this->log->position());

        $this->em->clear();
        $again = $this->em->find(CrateItem::class, $id);
        self::assertInstanceOf(CrateItem::class, $again);
        $again->quantity = 3;
        $this->em->flush();

        self::assertSame(['crate C-1 items.'.$id.'.quantity: 2 -> 3'], $this->facts());
    }

    public function testNothingIsSettledWhileATransactionIsOpenAroundTheFlush(): void
    {
        $x = $this->aLine();
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $x->quantity = 2;
        $this->em->flush();

        self::assertFalse($this->memory->settle($this->em), 'the application may still roll it back');

        $connection->rollBack();

        self::assertTrue($this->memory->settle($this->em));
        self::assertSame(1, (int) ($this->memory->rows()[CrateItem::class][(string) $x->id]['quantity'] ?? 0), 'and what was rolled back is not in it');
    }

    public function testARowNothingHoldsIsForgottenWhenSettledAndARowWorkStillNeedsIsNot(): void
    {
        $x = $this->aLine();
        $id = (string) $x->id;
        $x->quantity = 2;
        $this->em->flush();

        // Cleared with the work unsettled: the row's object is gone and the row is still here.
        $this->em->clear();
        unset($x);
        gc_collect_cycles();

        self::assertArrayHasKey($id, $this->memory->rows()[CrateItem::class] ?? [], 'what is not settled is not forgotten');
        self::assertSame([' items.'.$id.'.quantity: 1 -> 2'], array_map(static fn (string $fact): string => strstr($fact, ' items.') ?: $fact, $this->facts()));

        self::assertGreaterThan(0, $this->memory->size(), 'the premise: rows held');
        self::assertTrue($this->memory->settle($this->em));

        self::assertSame(0, $this->memory->size(), 'settled, and nothing holds it: forgotten');
    }

    public function testWhatAReplayLetGoOfReadStillCountsAmongTheStatementsRead(): void
    {
        // Asked for another manager, the replay is made again and reads the same statements
        // again: each reading counts, the ones of the replays let go of among them.
        $x = $this->aLine();
        $this->em->getConnection()->beginTransaction();
        $x->quantity = 2;
        $this->em->flush();
        $other = new \Doctrine\ORM\EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $read = [];

        foreach ([$this->em, $other, $this->em, $other] as $manager) {
            $before = $this->memory->statementsRead();
            $this->memory->replayed($manager);
            $read[] = $this->memory->statementsRead() - $before;
        }

        $this->em->getConnection()->rollBack();

        $each = $this->log->position() - $this->memory->settledAt();
        self::assertGreaterThan(0, $each, 'the premise: something to read');
        self::assertSame([$each, $each, $each, $each], $read);
    }

    public function testReadingWhatTheRowsHoldInALongTransactionReadsEachStatementOnce(): void
    {
        // A batch import in one transaction is hundreds of flushes before anything settles,
        // and what the rows hold is asked after each. Replaying everything since the last
        // settling every time is quadratic; the replay carries on from where it stopped.
        $x = $this->aLine();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $before = $this->memory->statementsRead();

        for ($i = 2; $i <= 101; ++$i) {
            $x->quantity = $i;
            $this->em->flush();
            self::assertSame($i, $this->now($x));
        }

        $connection->commit();

        $replay = $this->memory->replayed($this->em);
        self::assertSame($this->log->position() - $this->memory->settledAt(), $this->memory->statementsRead() - $before, 'every statement read once, however often it was asked');
        self::assertCount(100, $replay->facts());
    }

    public function testARowFirstRememberedAfterTheReplayWasKeptIsKnownToIt(): void
    {
        // In one transaction: the rows are asked about, which keeps the replay; then a line
        // loaded since is remembered at the next preFlush and written. The kept replay has to
        // learn of it, or the line's UPDATE is a row nothing said was there.
        $x = $this->aLine();
        $crate = $x->crate;
        self::assertNotNull($crate);
        $crate->add($z = new CrateItem('SKU-Z'));
        $this->em->flush();
        $this->memory->settle($this->em);
        $id = $z->id;
        $this->em->clear();
        gc_collect_cycles();
        $this->memory->settle($this->em);

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $x = $this->em->find(CrateItem::class, $x->id);
        self::assertInstanceOf(CrateItem::class, $x);
        $x->quantity = 2;
        $this->em->flush();
        self::assertSame(2, $this->now($x), 'the replay is kept from here');

        $z = $this->em->find(CrateItem::class, $id);
        self::assertInstanceOf(CrateItem::class, $z);
        $z->quantity = 6;
        $this->em->flush();
        $connection->commit();

        self::assertSame(6, $this->now($z));
        self::assertSame(['crate C-1 items.'.$x->id.'.quantity: 1 -> 2', 'crate C-1 items.'.$id.'.quantity: 1 -> 6'], $this->facts());
    }

    public function testAnOldObjectStillHeldAfterAClearDoesNotHoldTheRowBack(): void
    {
        $old = $this->aLine();
        $id = $old->id;

        $old->quantity = 2;
        $this->em->flush();
        self::assertTrue($this->memory->settle($this->em));

        $this->em->clear();
        $new = $this->em->find(CrateItem::class, $id);
        self::assertInstanceOf(CrateItem::class, $new);
        self::assertNotSame($old, $new, 'the premise: the application still holds the old object, and Doctrine gave a new one');

        $new->quantity = 3;
        $this->em->flush();

        self::assertSame(['crate C-1 items.'.$id.'.quantity: 2 -> 3'], $this->facts());
        self::assertSame(1, $old->quantity === 2 ? 1 : 0, 'and the old object is as the application left it');
    }

    public function testWhatIsNotAuditedIsNotRemembered(): void
    {
        $this->em->persist($vehicle = new Vehicle());
        $this->em->flush();
        $this->memory->settle($this->em);

        $vehicle->plate = 'BB-2';
        $this->em->flush();

        self::assertArrayNotHasKey(Vehicle::class, $this->memory->rows());
        self::assertSame([], $this->facts());
    }

    public function testARowWhoseIdIsKnownBeforeItsInsertIsNotRememberedUntilItIsThere(): void
    {
        // An id the application gives -- or one a sequence hands out at persist(), which is
        // PostgreSQL under DBAL 3 -- is there before the row is. A refused flush computes its
        // change sets before it is refused, and that gives a new entity Doctrine's memory of
        // the row it means to insert: remembered at the next preFlush as a row that exists, it
        // made an emptying count one row more than it took, and its own INSERT read as a row
        // that was already there, so it never arrived.
        $this->em->persist($ledger = new Ledger('L-1'));
        $this->em->flush();
        $this->memory->settle($this->em);

        $ledger->add($line = new LedgerLine('LL-1', 'first'));
        $this->em->persist($line);
        $this->refused();
        $this->em->flush();

        self::assertSame([sprintf('ledger %d lines.LL-1: null -> "first"', $ledger->id)], $this->facts());
    }

    public function testAnElementIsWatchedWhateverCollectionItsOwnerDeclaresFirst(): void
    {
        // Rack declares an owning ManyToMany before the inverse collection of notes. Asking the
        // owning side what it is mapped by throws, and the decision about RackNote was that
        // exception caught as "not watched": its rows were never remembered, and nothing that
        // changed inside a note could be read.
        $this->em->persist($rack = new Rack('R-1'));
        $rack->note($note = new RackNote('first'));
        $this->em->flush();
        $this->memory->settle($this->em);

        $note->text = 'second';
        $this->em->flush();

        self::assertArrayHasKey(RackNote::class, $this->memory->replayed($this->em)->rows());
        self::assertSame([sprintf('rack %d notes.%d.text: "first" -> "second"', $rack->id, $note->id)], $this->facts());
    }

    public function testAReplayKeptInsideATransactionDoesNotKeepItsManagerAlive(): void
    {
        // Nothing is settled while the application's transaction is open, so the replay is
        // kept from one read to the next -- and a manager the application replaces meanwhile,
        // as ManagerRegistry::resetManager() does, has to be free to go with all it manages.
        $this->em->persist(new Vehicle());
        $this->em->flush();

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->em->persist(new Crate('C-2'));
        $this->em->flush();
        $this->memory->replayed($this->em);
        self::assertFalse($this->memory->settle($this->em), 'the premise: the replay is kept');

        $gone = \WeakReference::create($this->em);
        $this->em->clear();
        $this->reopen();
        gc_collect_cycles();

        self::assertNull($gone->get(), 'the replaced manager is not held by what the rows remember');

        $connection->rollBack();
    }

    public function testARowLoadedOutsideAFlushIsRememberedAtTheNextOne(): void
    {
        $x = $this->aLine();
        $id = $x->id;
        $this->em->clear();
        $this->memory->settle($this->em);
        gc_collect_cycles();

        $again = $this->em->find(CrateItem::class, $id);
        self::assertInstanceOf(CrateItem::class, $again);

        self::assertArrayNotHasKey((string) $id, $this->memory->rows()[CrateItem::class] ?? [], 'a find() outside a flush pays nothing');

        $again->quantity = 4;
        $this->em->flush();

        self::assertSame(['crate C-1 items.'.$id.'.quantity: 1 -> 4'], $this->facts(), 'and the next preFlush remembered it before anything changed it');
    }

    private function aLine(): CrateItem
    {
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($x = new CrateItem('SKU-X'));
        $this->em->flush();
        $this->memory->settle($this->em);

        return $x;
    }

    /** What the row holds now, by the memory and the log. */
    private function now(CrateItem $line): int
    {
        $row = $this->memory->replayed($this->em)->rows()[CrateItem::class][(string) $line->id] ?? [];

        return (int) ($row['quantity'] ?? 0);
    }

    /**
     * @return list<string>
     */
    private function facts(): array
    {
        $replay = $this->memory->replayed($this->em);
        self::assertSame([], $replay->doubts());

        return array_map(
            static fn (array $fact): string => sprintf('%s %s %s: %s -> %s', $fact['type'], $fact['id'], $fact['field'], json_encode($fact['old']), json_encode($fact['new'])),
            $replay->facts(),
        );
    }

    private function refused(): void
    {
        $veto = new class {
            public function onFlush(): void
            {
                throw new \DomainException('refused');
            }
        };

        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        try {
            $this->em->flush();
        } catch (\DomainException) {
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $veto);
        }
    }

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
        } catch (\RuntimeException) {
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::postUpdate], $breaker);
        }
    }

    private function inThePostUpdateOf(object $entity, \Closure $what): void
    {
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($entity, $what) {
            private bool $ran = false;

            public function __construct(private readonly object $entity, private readonly \Closure $what)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                if ($this->ran || $args->getObject() !== $this->entity) {
                    return;
                }

                $this->ran = true;
                ($this->what)();
            }
        });
    }
}
