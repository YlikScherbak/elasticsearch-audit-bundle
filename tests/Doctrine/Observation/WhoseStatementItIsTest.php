<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Whose each statement is, when the listener labels the frame its flush opens.
 *
 * The listener still decides nothing from the log; this is what the log would say, on real
 * flushes, nested the ways the acceptance tests nest them. Every statement the connection ran
 * is listed with its owner and fate, so a statement given to the wrong flush shows as that,
 * not as a missing one.
 */
final class WhoseStatementItIsTest extends DoctrineTestCase
{
    private StatementLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = $this->watchTheConnection();
    }

    public function testAFlushOwnsWhatItRan(): void
    {
        [, $x] = $this->aCrateWithTwoLines();

        $from = $this->log->position();
        $x->quantity = 2;
        $this->em->flush();

        self::assertSame([['UPDATE CrateItem quantity=2', 'flush 2', StatementLog::COMMITTED]], $this->since($from));
    }

    public function testANestedFlushOwnsWhatItRanForTheOuterOne(): void
    {
        // S1b: the outer flush wrote X and scheduled Y; the nested one wrote Y with 7, the
        // value its change set merged in. That statement is the nested flush's.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $y->quantity = 7;
            $this->em->flush();
        });

        $from = $this->log->position();
        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        // Without savepoints -- DBAL 3's default -- a nested transaction opens nothing on the
        // wire, so there is no frame for the nested flush to own and its statement is the
        // enclosing flush's. A limit of watching the connection, and written down as one.
        $connection = $this->em->getConnection();
        $nested = !method_exists($connection, 'getNestTransactionsWithSavepoints') || $connection->getNestTransactionsWithSavepoints() ? 'flush 3' : 'flush 2';

        self::assertSame([
            ['UPDATE CrateItem quantity=2', 'flush 2', StatementLog::COMMITTED],
            ['UPDATE CrateItem quantity=7', $nested, StatementLog::COMMITTED],
        ], $this->since($from));
    }

    public function testANestedFlushRefusedBeforeItOpenedAnythingOwnsNothing(): void
    {
        // The nested flush said it was about to begin and a listener behind this one refused
        // it; it opened no frame. What runs afterwards -- the outer flush's own leftover, Y --
        // is the outer flush's.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function (): void {
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
        });

        $from = $this->log->position();
        $x->quantity = 2;
        $y->quantity = 2;
        $this->em->flush();

        self::assertSame([
            ['UPDATE CrateItem quantity=2', 'flush 2', StatementLog::COMMITTED],
            ['UPDATE CrateItem quantity=2', 'flush 2', StatementLog::COMMITTED],
        ], $this->since($from));
    }

    public function testANestedFlushThatDiedOwnsNothingAndWhatItRanDidNotHappen(): void
    {
        // 6/A: the nested flush wrote Y 1 -> 7 and died; Doctrine rolled back to its
        // savepoint, and the outer flush committed X. Without savepoints the whole
        // transaction is marked and refused, and nothing happened at all.
        [, $x, $y] = $this->aCrateWithTwoLines();

        $this->inThePostUpdateOf($x, function () use ($y): void {
            $y->quantity = 7;
            $breaker = new class($y) {
                public function __construct(private readonly object $y)
                {
                }

                public function postUpdate(PostUpdateEventArgs $args): void
                {
                    if ($args->getObject() === $this->y) {
                        throw new \RuntimeException('the nested flush dies after its statement');
                    }
                }
            };
            $this->em->getEventManager()->addEventListener([Events::postUpdate], $breaker);

            try {
                $this->em->flush();
            } catch (\RuntimeException) {
            } finally {
                $this->em->getEventManager()->removeEventListener([Events::postUpdate], $breaker);
            }
        });

        $from = $this->log->position();
        $x->quantity = 2;

        $connection = $this->em->getConnection();
        $savepoints = !method_exists($connection, 'getNestTransactionsWithSavepoints') || $connection->getNestTransactionsWithSavepoints();

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            self::assertFalse($savepoints, 'the premise: only a rollback-only transaction refuses the outer commit, not '.$e->getMessage());
        }

        self::assertSame($savepoints ? [
            ['UPDATE CrateItem quantity=2', 'flush 2', StatementLog::COMMITTED],
            ['UPDATE CrateItem quantity=7', 'flush 2', StatementLog::VOID],
        ] : [
            ['UPDATE CrateItem quantity=2', 'flush 2', StatementLog::VOID],
            ['UPDATE CrateItem quantity=7', 'flush 2', StatementLog::VOID],
        ], $this->since($from), 'the dead flush\'s frame stops being its own the moment it is rolled back to');
    }

    public function testTheObserverRunsNoStatementOfItsOwn(): void
    {
        [, $x] = $this->aCrateWithTwoLines();

        $this->queries = [];
        $x->quantity = 2;
        $this->em->flush();

        self::assertSame(['UPDATE CrateItem SET quantity = ? WHERE id = ?'], array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => !str_starts_with($sql, 'SELECT'),
        )));
    }

    /**
     * @return array{Crate, CrateItem, CrateItem}
     */
    private function aCrateWithTwoLines(): array
    {
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($x = new CrateItem('SKU-X'));
        $crate->add($y = new CrateItem('SKU-Y'));
        $this->em->flush();

        return [$crate, $x, $y];
    }

    /**
     * Every statement since a position: what it did, whose it is, and what became of it.
     *
     * The fixture's own flush is the listener's first, so a test's flush is the second and
     * a flush nested inside it the third.
     *
     * @return list<array{string, string, string}>
     */
    private function since(int $from): array
    {
        $said = [];

        for ($at = $from + 1; $at <= $this->log->position(); ++$at) {
            $statement = $this->log->statement($at);

            if ($statement === null) {
                continue;
            }

            $shape = StatementShape::read($statement['sql']);
            $what = $shape === null ? $statement['sql'] : strtoupper($shape->kind).' '.$shape->table.' '.implode(',', array_map(
                static fn (string $column, ?int $parameter): string => $column.'='.var_export($parameter === null ? null : $statement['params'][$parameter] ?? null, true),
                array_keys($shape->assigned),
                $shape->assigned,
            ));
            $owner = $this->log->ownerOf($at);

            $said[] = [trim($what), $owner === null ? 'nobody' : 'flush '.$owner, $this->log->fate($at)];
        }

        return $said;
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
