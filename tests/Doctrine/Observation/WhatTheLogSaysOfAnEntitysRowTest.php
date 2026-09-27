<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Beacon;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Press;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Switchboard;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\SwitchMode;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * What the connection's log says a statement did to a row of an audited class -- the facts
 * step 5 builds an entity's records from (5.1: facts only; nothing reads them yet).
 *
 * Each scenario runs inside a transaction of the application's own, so that nothing the
 * listener remembers is settled before the facts are read, and reads the facts from the rows
 * the listener itself remembers: its seeding at preFlush and at a load during a flush is part
 * of what is under test.
 */
final class WhatTheLogSaysOfAnEntitysRowTest extends DoctrineTestCase
{
    private StatementLog $log;

    private int $from = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = $this->watchTheConnection(FailurePolicy::Log);
    }

    public function testACreationOfAJoinedEntityIsAnInsertIntoEachOfItsTablesForOneRow(): void
    {
        // Two INSERTs, one per table, one row: the root's columns in one, the subclass's in the
        // other, both of the subclass and under one key. Whether they are one creation is 5.2's
        // -- here, that each is there, at a position of its own.
        $this->begin();
        $this->em->persist($press = new Press('One'));
        $this->em->flush();

        $facts = $this->facts();

        self::assertSame([
            ['insert', Press::class, (string) $press->id, ['name' => [null, 'One']]],
            ['insert', Press::class, (string) $press->id, ['tonnage' => [null, 1]]],
        ], self::said($facts));
        self::assertNotSame($facts[0]['at'], $facts[1]['at'], 'two statements, two positions');
    }

    public function testACreationWithNothingToSayIsStillACreationAndItsRemovalKnowsTheRow(): void
    {
        // Its one audited column is nothing, so no column moves: the creation is a fact all the
        // same. And its removal, later, carries the key and the row as it stood.
        $this->begin();
        $this->em->persist($beacon = new Beacon());
        $this->em->flush();
        $id = (string) $beacon->id;

        $this->em->remove($beacon);
        $this->em->flush();

        self::assertSame([
            ['insert', Beacon::class, $id, ['label' => [null, null]]],
            ['delete', Beacon::class, $id, ['label' => [null, null]]],
        ], self::said($this->facts()));
        self::assertSame(['id' => (int) $id], array_map('intval', $this->facts()[1]['key']));
    }

    public function testAManyToOneIsTheKeyItNamesInACreationAndInAChange(): void
    {
        $this->em->persist($ada = new Author('Ada'));
        $this->em->persist($bea = new Author('Bea'));
        $this->em->flush();

        $this->begin();
        $article = new Article('Hello');
        $article->author = $ada;
        $this->em->persist($article);
        $this->em->flush();
        $article->author = $bea;
        $this->em->flush();

        $said = self::said($this->facts());

        self::assertSame(['insert', Article::class, (string) $article->id], \array_slice($said[0], 0, 3));
        self::assertSame([null, $ada->id], array_map(static fn (mixed $v): mixed => $v === null ? null : (int) $v, $said[0][3]['author']), 'the author it was created with, as its key');
        self::assertSame(['update', Article::class, (string) $article->id, ['author' => [$ada->id, $bea->id]]], [$said[1][0], $said[1][1], $said[1][2], array_map(static fn (array $sides): array => array_map('intval', $sides), $said[1][3])]);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function whetherTheManagerIsCleared(): iterable
    {
        yield 'the object from its creation' => [false];
        yield 'loaded again after a clear' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whetherTheManagerIsCleared')]
    public function testBothSidesOfAChangeAreTheRowsPrecision(bool $cleared): void
    {
        // Created with a moment half a second past ten, in a column held to the second: the row
        // holds ten. Seeded from the object Doctrine holds -- the creation's, or one loaded
        // again -- the remembered side is the row's too. A move to another fraction of the same
        // second reaches the row as nothing; a move to the next second is exactly that move. A
        // boolean and an enum change beside it, as the object holds them.
        $board = new Switchboard();
        $board->checkedAt = new \DateTimeImmutable('2026-09-25 10:00:00.123456');
        $this->em->persist($board);
        $this->em->flush();

        if ($cleared) {
            $this->em->clear();
            $board = $this->em->find(Switchboard::class, $board->id);
            self::assertInstanceOf(Switchboard::class, $board);
        }

        $this->begin();
        $board->checkedAt = new \DateTimeImmutable('2026-09-25 10:00:00.654321');
        $this->em->flush();
        $board->checkedAt = new \DateTimeImmutable('2026-09-25 10:00:01');
        $board->lit = true;
        $board->mode = SwitchMode::Automatic;
        $this->em->flush();

        // One fact a statement: the fraction's UPDATE said nothing, the next one said three
        // things. The order of the fields is the order the statement set them in, which is
        // Doctrine's, so it is compared without it.
        $facts = $this->facts();

        self::assertSame(['update', 'update'], array_column($facts, 'statement'), 'the premise: Doctrine wrote both, the fraction too');
        self::assertSame([], $facts[0]['fields'], 'the fraction of a second reached the row as nothing');

        $second = array_map(
            static fn (array $sides): array => array_map(static fn (mixed $v): mixed => $v instanceof \DateTimeInterface ? $v->format('H:i:s.u') : $v, $sides),
            $facts[1]['fields'],
        );
        ksort($second);

        self::assertSame([
            'checkedAt' => ['old' => '10:00:00.000000', 'new' => '10:00:01.000000'],
            'lit' => ['old' => false, 'new' => true],
            'mode' => ['old' => SwitchMode::Manual, 'new' => SwitchMode::Automatic],
        ], $second, 'and the next second is exactly that move, a boolean and an enum as the object holds them');
    }

    public function testARowLoadedAfterItsFlushBeganAndRemovedThereIsKnownAsItStood(): void
    {
        // The flush has had its preFlush when a listener clears the manager, loads Y -- which
        // nothing had loaded before -- and removes it; the same flush carries out the DELETE.
        // Y is remembered from its load during the flush, and its removal carries the row.
        $this->em->persist($x = new Article('X'));
        $this->em->persist($y = new Article('Y, as it stood'));
        $this->em->flush();
        [$xId, $yId] = [$x->id, $y->id];

        // Y let go of, and the rows nobody holds forgotten at the next settling: from here the
        // listener knows Y's row from nowhere but a load of it.
        unset($x, $y);
        $this->em->clear();
        gc_collect_cycles();
        $this->em->persist(new Author('somebody'));
        $this->em->flush();

        $x = $this->em->find(Article::class, $xId);
        self::assertInstanceOf(Article::class, $x);
        self::assertFalse($this->em->getUnitOfWork()->tryGetById($yId, Article::class), 'the premise: nothing has loaded Y');
        self::assertArrayNotHasKey((string) $yId, $this->memory()->rows()[Article::class] ?? [], 'the premise: and the listener does not remember its row');

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $yId) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly mixed $yId)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;
                $this->em->clear();
                $again = $this->em->find(Article::class, $this->yId);

                if ($again !== null) {
                    $this->em->remove($again);
                }
            }
        });

        $this->begin();
        $x->title = 'X, again';
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$yId]), 'the premise: Y went in the same flush');

        $said = self::said($this->facts());
        $removal = array_values(array_filter($said, static fn (array $fact): bool => $fact[0] === 'delete'));

        self::assertSame([['delete', Article::class, (string) $yId]], array_map(static fn (array $fact): array => \array_slice($fact, 0, 3), $removal));
        self::assertSame([null, 'Y, as it stood'], [null, $removal[0][3]['title'][0]], 'as it stood before it went');
        self::assertSame([], $this->memory()->replayed($this->em)->doubts(), 'and no statement was a row nobody knew');
    }

    protected function tearDown(): void
    {
        // The facts are read inside the scenario's transaction; it is closed here, or on a real
        // database it holds its locks and the next test's schema waits on them for ever.
        $connection = $this->em->getConnection();

        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /** A transaction of the application's own around what follows, and where the log stands. */
    private function begin(): void
    {
        $this->em->getConnection()->beginTransaction();
        $this->from = $this->log->position();
    }

    /**
     * The row facts since the scenario began, from the rows the listener remembers.
     *
     * @return list<array{statement: string, class: class-string, id: string, key: array<string, mixed>, at: int, flush: int|null, fields: array<string, array{old: mixed, new: mixed}>}>
     */
    private function facts(): array
    {
        return array_values(array_filter(
            $this->memory()->replayed($this->em)->rowFacts(),
            fn (array $fact): bool => $fact['at'] > $this->from,
        ));
    }

    /**
     * @param list<array{statement: string, class: class-string, id: string, fields: array<string, array{old: mixed, new: mixed}>}> $facts
     *
     * @return list<array{0: string, 1: class-string, 2: string, 3: array<string, array{0: mixed, 1: mixed}>}>
     */
    private static function said(array $facts): array
    {
        return array_map(static fn (array $fact): array => [
            $fact['statement'],
            $fact['class'],
            $fact['id'],
            array_map(static fn (array $sides): array => [$sides['old'], $sides['new']], $fact['fields']),
        ], $facts);
    }

    private function memory(): RowMemory
    {
        foreach ($this->em->getEventManager()->getListeners(Events::onFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                $memory = (new \ReflectionProperty(AuditSubscriber::class, 'rows'))->getValue($listener);
                self::assertInstanceOf(RowMemory::class, $memory);

                return $memory;
            }
        }

        self::fail('the premise: the listener is attached');
    }
}
