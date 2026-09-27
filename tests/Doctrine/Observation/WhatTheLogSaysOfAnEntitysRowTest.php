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
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Pouch;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Preference;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Sku;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Press;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay;
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

        $this->end();
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

        $this->end();
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

        $this->end();
    }

    public function testARowADeleteTookIsKnownAsItStoodBeforeItWent(): void
    {
        // Renamed in memory, never written, and removed: what the row held is the name it had.
        // A row still there, or one of a class nobody watches, is nothing this can say.
        $this->em->persist($hub = new Relay('hub'));
        $this->em->persist($kept = new Relay('kept'));
        $this->em->persist($ada = new Author('Ada'));
        $this->em->flush();
        $key = ['id' => $hub->id];

        $this->begin();
        $hub->name = 'renamed';
        $this->em->remove($hub);
        $this->em->remove($ada);
        $this->em->flush();

        $replay = $this->memory()->replayed($this->em);
        $copy = $replay->asItStoodBeforeItWent(Relay::class, $key);

        self::assertInstanceOf(Relay::class, $copy);
        self::assertNotSame($hub, $copy);
        self::assertSame(['hub', $key['id']], [$copy->name, $copy->id]);
        self::assertNull($replay->asItStoodBeforeItWent(Relay::class, ['id' => $kept->id]), 'a row still there');
        self::assertNull($replay->asItStoodBeforeItWent(Author::class, ['id' => $ada->id]), 'a row nothing watches');

        $this->end();
    }

    public function testAnElementsFactCarriesItsOwnersContextWhereItRan(): void
    {
        // A line goes from one to two; the application's own SQL moves the crate's status; the
        // line goes to three. The owner never had an UPDATE of Doctrine's: each fact says what
        // its row held when the line's statement ran.
        $this->unownedStatementsAreExpected = true;
        $crate = new Crate('C-1');
        $crate->add($line = new CrateItem('apple'));
        $this->em->persist($crate);
        $this->em->flush();

        $this->begin();
        $line->quantity = 2;
        $this->em->flush();
        $this->em->getConnection()->update('Crate', ['status' => 'raw'], ['code' => 'C-1']);
        $line->quantity = 3;
        $this->em->flush();

        $facts = array_values(array_filter(
            $this->memory()->replayed($this->em)->facts(),
            fn (array $fact): bool => $fact['at'] > $this->from && $fact['element'] !== null,
        ));

        self::assertSame([[1, 2], [2, 3]], array_map(static fn (array $fact): array => [$fact['old'], $fact['new']], $facts), 'the premise: the two changes of the line');
        self::assertSame([['status' => 'packed'], ['status' => 'raw']], array_column($facts, 'ownerContext'));

        $this->end();
    }

    public function testAnOwningCollectionsJoinRowsAreNoFactsYet(): void
    {
        // What an owning many-to-many went through is read from the collection's snapshot, at
        // its owner's post* events (AuditSubscriber::$collectionSnapshots), because its join
        // rows are not facts of the log yet. The day they are -- step 5.3 -- this fails: take
        // the snapshots out of the listener, and this test with them.
        $this->em->persist($php = new Tag('php'));
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();

        $this->begin();
        $article->tags->add($php);
        $this->em->flush();

        $replay = $this->memory()->replayed($this->em);
        $theirs = array_filter($replay->facts(), fn (array $fact): bool => $fact['at'] > $this->from);

        self::assertNotSame([], array_filter(
            iterator_to_array((function (): \Generator {
                for ($at = $this->from + 1; $at <= $this->log->position(); ++$at) {
                    yield $this->log->statement($at)['sql'] ?? '';
                }
            })()),
            static fn (string $sql): bool => str_contains($sql, 'article_tag'),
        ), 'the premise: the join row was written');
        self::assertSame([], $theirs, 'the join rows are facts now (5.3): take AuditSubscriber::$collectionSnapshots out, and this test with it');
        self::assertSame([], array_filter($this->facts(), static fn (array $fact): bool => $fact['class'] !== Article::class), 'nor are they a fact of any row');

        $this->end();
    }

    public function testAFactCarriesWhatTheAlwaysRecordedFieldsHeldInTheRowOnceItRan(): void
    {
        // Created as a draft, then its title changed -- the status beside it is the row's -- and
        // then, in a postUpdate listener, the object's status moved to something no statement
        // ever wrote. The context the change carries is the row's: still the draft.
        $this->begin();
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($article) {
            public function __construct(private readonly Article $article)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->article) {
                    $this->article->status = 'never written';
                }
            }
        });

        $article->title = 'Hello again';
        $this->em->flush();

        $facts = $this->facts();

        self::assertSame(['insert', 'update'], array_column($facts, 'statement'), 'the premise: a creation and a change');
        self::assertSame('never written', $article->status, 'the premise: the object moved on after the statement');
        self::assertSame([['status' => 'draft'], ['status' => 'draft']], array_column($facts, 'context'), 'the row\'s status, once each statement ran');

        $this->end();
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

        $this->end();
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

        $this->end();
    }

    public function testARowRememberedFromItsObjectIsKeyedAsTheStatementsKeyIt(): void
    {
        // One rule, in one place: what the listener remembers of a row is the row's form, and
        // the replay alone brings it to the object's. The key is where that shows first: an
        // identifier of a type of its own is an object on the entity and a string in the
        // statement, and a row remembered from the entity -- at the preFlush after a load --
        // has to be keyed as the statement keys it, or the UPDATE finds no row it knows.
        $this->em->persist(new Pouch(new Sku('P-1'), 'coins'));
        $this->em->flush();
        $pouch = $this->loadedAfresh(Pouch::class, 'P-1');

        $this->begin();
        $pouch->contents = 'keys';
        $this->em->flush();

        self::assertSame([['update', Pouch::class, 'P-1', ['contents' => ['coins', 'keys']]]], self::said($this->facts()));
        self::assertSame([], $this->memory()->replayed($this->em)->doubts(), 'and the row was one it knew');

        $this->end();
    }

    public function testARowRememberedFromItsObjectHoldsItsValuesAsTheRowDoes(): void
    {
        // And the values: an array is held by the row as JSON, and the replay reads it back from
        // that. Remembered as the object holds it, it could not be read back at all.
        $preference = new Preference();
        $preference->options = ['theme' => 'dark'];
        $this->em->persist($preference);
        $this->em->flush();
        $id = $preference->id;
        unset($preference);
        $preference = $this->loadedAfresh(Preference::class, $id);

        $this->begin();
        $preference->options = ['theme' => 'light'];
        $this->em->flush();

        self::assertSame([['update', Preference::class, (string) $preference->id, ['options' => [['theme' => 'dark'], ['theme' => 'light']]]]], self::said($this->facts()));

        $this->end();
    }

    /**
     * An entity loaded again with nothing of it remembered: the object let go of, the manager
     * cleared, and the rows nobody holds forgotten at a settling -- so that the listener takes
     * its row from the object, at the next preFlush, and from nowhere else.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function loadedAfresh(string $class, mixed $id): object
    {
        $this->em->clear();
        gc_collect_cycles();
        $this->em->persist(new Author('somebody'));
        $this->em->flush();

        $entity = $this->em->find($class, $id instanceof Sku ? $id : ($class === Pouch::class ? new Sku((string) $id) : $id));
        self::assertInstanceOf($class, $entity);
        self::assertSame([], array_filter(
            $this->memory()->rows()[$class] ?? [],
            static fn (mixed $row, string $key): bool => $key === (string) $id,
            \ARRAY_FILTER_USE_BOTH,
        ), 'the premise: nothing of its row is remembered but what its object will give');

        return $entity;
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
     * @return list<array{statement: string, class: class-string, id: string, key: array<string, mixed>, at: int, flush: int|null, fields: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>}>
     */
    private function facts(): array
    {
        return array_values(array_filter(
            $this->memory()->replayed($this->em)->rowFacts(),
            fn (array $fact): bool => $fact['at'] > $this->from,
        ));
    }

    /** The scenario's transaction closed, once its facts are read. */
    private function end(): void
    {
        $this->em->getConnection()->rollBack();
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
