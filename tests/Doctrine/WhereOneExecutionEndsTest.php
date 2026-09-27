<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Kiln;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * Where one execution of a row ends and the next begins, when the statements say so only by
 * where they ran: each of the conditions the reader groups a JOINED change and folds a creation
 * by, in a scenario of its own -- and the limit of what the log can tell, measured and named.
 *
 * What the log holds is the SQL, its parameters and the boundaries it keeps: frames, owners,
 * where each flush began. What it cannot tell apart from those, it does not.
 */
final class WhereOneExecutionEndsTest extends DoctrineTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function whetherTheConnectionUsesSavepoints(): iterable
    {
        yield 'with savepoints' => [true];
        yield 'without them, where DBAL 3 allows it' => [false];
    }

    /**
     * The flush writes the root's column; in the row's postUpdate the application opens a
     * savepoint of its own -- no flush -- writes the subclass's column of the same row, and
     * releases it. Next to each other in the log (a savepoint statement takes no place there),
     * of one row, one kind, the hierarchy's two tables, one owner, no flush begun between: what
     * tells them apart is the frame, and only that. Two executions.
     */
    public function testAChangeInASavepointOfTheApplicationsIsAnExecutionOfItsOwn(): void
    {
        $kiln = $this->aKiln();
        $this->inItsPostUpdate($kiln, function () use ($kiln): void {
            $connection = $this->em->getConnection();
            $connection->beginTransaction();
            $connection->update('Kiln', ['heat' => 1234], ['id' => $kiln->id]);
            $connection->commit();
        });

        $kiln->label = 'two';
        $this->em->flush();

        [$root, $child] = $this->positionsOf('UPDATE Oven', 'UPDATE Kiln');
        self::assertSame($root + 1, $child, 'the premise: next to each other in the log');
        self::assertNotSame($this->statements->frameOf($root), $this->statements->frameOf($child), 'the premise: in two frames');
        self::assertSame($this->statements->ownerOf($root), $this->statements->ownerOf($child), 'the premise: one owner');

        self::assertSame([
            ['oven', 'update', ['firing' => ['bisque', 'bisque'], 'label' => ['one', 'two'], 'site' => ['north', 'north']]],
            ['oven', 'update', ['firing' => ['bisque', 'bisque'], 'heat' => [900, 1234], 'site' => ['north', 'north']]],
        ], $this->said());
    }

    /**
     * The same, rolled back: what the savepoint held is nothing the row took, and the history
     * is the root's change alone -- which is the statement's fate, not where it ran.
     */
    public function testAChangeInASavepointTheApplicationRolledBackIsNone(): void
    {
        $kiln = $this->aKiln();
        $this->inItsPostUpdate($kiln, function () use ($kiln): void {
            $connection = $this->em->getConnection();
            $connection->beginTransaction();
            $connection->update('Kiln', ['heat' => 1234], ['id' => $kiln->id]);
            $connection->rollBack();
        });

        $kiln->label = 'two';
        $this->em->flush();

        self::assertSame([
            ['oven', 'update', ['firing' => ['bisque', 'bisque'], 'label' => ['one', 'two'], 'site' => ['north', 'north']]],
        ], $this->said());
    }

    /**
     * The limit. The flush writes the root's column; in the row's postUpdate the application
     * writes the subclass's column of the same row itself, in the flush's own frame. By the SQL,
     * its parameters and the boundaries the log keeps, that is one change of a JOINED row
     * written a table at a time -- which is what the persister does -- and the reader says so:
     * one record, both columns, the context once both ran. What it cannot say is that two hands
     * wrote it; nothing it holds is lost.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('whetherTheConnectionUsesSavepoints')]
    public function testTheApplicationsStatementRightAfterTheFlushsOnTheOtherTableReadsAsOneChange(bool $savepoints): void
    {
        $this->watchTheConnection(FailurePolicy::Log, savepoints: $savepoints);
        $kiln = $this->aKiln();
        $this->inItsPostUpdate($kiln, function () use ($kiln): void {
            $this->em->getConnection()->update('Kiln', ['heat' => 1234, 'firing' => 'glaze'], ['id' => $kiln->id]);
        });

        $kiln->label = 'two';
        $this->em->flush();

        [$root, $child] = $this->positionsOf('UPDATE Oven', 'UPDATE Kiln');
        self::assertSame([$root + 1, $this->statements->frameOf($root)], [$child, $this->statements->frameOf($child)], 'the premise: next to each other, in one frame');

        self::assertSame([
            ['oven', 'update', ['firing' => ['bisque', 'glaze'], 'heat' => [900, 1234], 'label' => ['one', 'two'], 'site' => ['north', 'north']]],
        ], $this->said());
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function savepointsAndATransactionOfTheApplications(): iterable
    {
        yield 'with savepoints' => [true, false];
        yield 'without them, where DBAL 3 allows it' => [false, false];
        yield 'with savepoints, in a transaction of the application\'s' => [true, true];
        yield 'without them, in a transaction of the application\'s' => [false, true];
    }

    /**
     * A nested flush's last statement writes the root's column of a row; the flush around it,
     * back from it, has the application write the subclass's column of the same row -- next to
     * each other, no flush begun between them: the nested one began before its own statement.
     * With savepoints the two ran in two frames and under two owners, and are two executions.
     * Without them nothing on the wire tells the nested flush's statements from the outer's --
     * one frame, one owner -- and they read as one change (nested_flush_provenance: outer).
     *
     * And inside a transaction of the application's, without savepoints: neither flush opens a
     * frame, the application's is the one they run in, and each flush claims what ran while it
     * did -- one frame, two owners. There the owner is all that says they are two.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('savepointsAndATransactionOfTheApplications')]
    public function testBackFromANestedFlushTheOuterFlushsStatementOnTheOtherTableIsAnotherChange(bool $savepoints, bool $inATransaction): void
    {
        $this->watchTheConnection(FailurePolicy::Log, savepoints: $savepoints);
        $kiln = $this->aKiln();
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();
        $this->gateway->documents = [];

        $this->inItsPostUpdate($article, function () use ($kiln): void {
            $kiln->label = 'nested';
            $this->em->flush();
            $this->em->getConnection()->update('Kiln', ['heat' => 1234], ['id' => $kiln->id]);
        });

        if ($inATransaction) {
            $this->em->getConnection()->beginTransaction();
        }

        $article->title = 'Hello again';
        $this->em->flush();

        if ($inATransaction) {
            $this->em->getConnection()->commit();
        }

        [$root, $child] = $this->positionsOf('UPDATE Oven', 'UPDATE Kiln');
        self::assertSame($root + 1, $child, 'the premise: next to each other in the log');
        self::assertFalse($this->statements->aFlushStartedAfter($root), 'the premise: no flush began between them');

        $ovens = array_values(array_filter($this->said(), static fn (array $record): bool => $record[0] === 'oven'));

        if (!$this->savepointsApart() && $inATransaction) {
            self::assertSame($this->statements->frameOf($root), $this->statements->frameOf($child), 'the premise: one frame, the application\'s');
        }

        if ($this->savepointsApart() || $inATransaction) {
            self::assertNotSame($this->statements->ownerOf($root), $this->statements->ownerOf($child), 'the premise: two owners');
            self::assertSame([
                ['oven', 'update', ['firing' => ['bisque', 'bisque'], 'label' => ['one', 'nested'], 'site' => ['north', 'north']]],
                ['oven', 'update', ['firing' => ['bisque', 'bisque'], 'heat' => [900, 1234], 'site' => ['north', 'north']]],
            ], $ovens);
        } else {
            self::assertSame([$this->statements->frameOf($root), $this->statements->ownerOf($root)], [$this->statements->frameOf($child), $this->statements->ownerOf($child)], 'the premise: one frame, one owner');
            self::assertSame([
                ['oven', 'update', ['firing' => ['bisque', 'bisque'], 'heat' => [900, 1234], 'label' => ['one', 'nested'], 'site' => ['north', 'north']]],
            ], $ovens);
        }
    }

    /**
     * A nested flush creates a relay with nothing to point at; the flush around it, back from
     * it, has the application give it a reference -- the shape of Doctrine's own completion of
     * a creation: the same row, no flush begun since, a reference its INSERT left empty. With
     * savepoints the creation is the nested flush's and the reference the outer's, and a flush
     * does not complete another's creation: a creation and a change. Without them the two are
     * one flush's on the wire, and read as the completion.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('whetherTheConnectionUsesSavepoints')]
    public function testAReferenceTheOuterFlushGivesARowTheNestedOneCreatedIsAChange(bool $savepoints): void
    {
        $this->watchTheConnection(FailurePolicy::Log, savepoints: $savepoints);
        $this->em->persist($hub = new Relay('hub'));
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();
        $this->gateway->documents = [];

        $spoke = new Relay('spoke');
        $this->inItsPostUpdate($article, function () use ($spoke, $hub): void {
            $this->em->persist($spoke);
            $this->em->flush();
            $this->em->getConnection()->update('Relay', ['next_id' => $hub->id], ['id' => $spoke->id]);
        });

        $article->title = 'Hello again';
        $this->em->flush();

        [$insert, $update] = $this->positionsOf('INSERT Relay', 'UPDATE Relay');
        self::assertSame($insert + 1, $update, 'the premise: next to each other in the log');
        self::assertFalse($this->statements->aFlushStartedAfter($insert), 'the premise: no flush began between them');

        $relays = array_values(array_filter($this->said(), static fn (array $record): bool => $record[0] === 'relay'));

        if ($this->savepointsApart()) {
            self::assertNotSame($this->statements->ownerOf($insert), $this->statements->ownerOf($update), 'the premise: two owners');
            self::assertSame([
                ['relay', 'create', ['name' => [null, 'spoke']]],
                ['relay', 'update', ['next' => [null, 'hub']]],
            ], $relays);
        } else {
            self::assertSame($this->statements->ownerOf($insert), $this->statements->ownerOf($update), 'the premise: one owner');
            self::assertSame([
                ['relay', 'create', ['name' => [null, 'spoke'], 'next' => [null, 'hub']]],
            ], $relays);
        }
    }

    private function aKiln(): Kiln
    {
        $this->em->persist($kiln = new Kiln());
        $this->em->flush();
        $this->gateway->documents = [];

        return $kiln;
    }

    /**
     * Runs once, in the entity's postUpdate, ahead of nothing in particular: what it does to the
     * log is after the entity's own statement either way.
     */
    private function inItsPostUpdate(object $entity, \Closure $what): void
    {
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($entity, $what) {
            private bool $ran = false;

            public function __construct(private readonly object $entity, private readonly \Closure $what)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->entity || $this->ran) {
                    return;
                }

                $this->ran = true;
                ($this->what)();
            }
        });
    }

    /**
     * Where the last statement of each kind and table ran, in the order asked.
     *
     * @return list<int>
     */
    private function positionsOf(string ...$wanted): array
    {
        $found = [];

        for ($at = 1; $at <= $this->statements->position(); ++$at) {
            $shape = StatementShape::read($this->statements->statement($at)['sql'] ?? '');

            if ($shape !== null) {
                $found[strtoupper($shape->kind).' '.$shape->table] = $at;
            }
        }

        return array_map(static fn (string $one): int => $found[$one] ?? self::fail('no '.$one.' in the log'), $wanted);
    }

    private function savepointsApart(): bool
    {
        $connection = $this->em->getConnection();

        return !method_exists($connection, 'getNestTransactionsWithSavepoints') || $connection->getNestTransactionsWithSavepoints();
    }

    /**
     * Each record as its type, event and changes -- the changes by name, each as [old, new].
     *
     * @return list<array{0: mixed, 1: mixed, 2: array<string, array{0: mixed, 1: mixed}>}>
     */
    private function said(): array
    {
        return array_map(static function (array $d): array {
            $changes = array_map(static fn (array $sides): array => [$sides['old'] ?? null, $sides['new'] ?? null], $d['changes']);
            ksort($changes);

            return [$d['objectType'], $d['event'], $changes];
        }, $this->documents());
    }
}
