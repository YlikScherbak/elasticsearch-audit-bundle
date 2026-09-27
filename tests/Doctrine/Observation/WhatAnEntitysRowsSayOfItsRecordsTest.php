<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\EntityRowRuns;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\HistoryReplay;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowIdentity;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Beacon;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Press;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vehicle;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * What the log's facts say an entity's records are: an execution per change of its row, the
 * event, the flush, the statements it was, and the changes as a record names them (5.2b: the
 * reader alone; nothing publishes from it yet).
 *
 * Each scenario runs inside a transaction of the application's own, as the facts' own test
 * does, so that nothing is settled before the facts are read.
 */
final class WhatAnEntitysRowsSayOfItsRecordsTest extends DoctrineTestCase
{
    private StatementLog $log;

    private int $from = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = $this->watchTheConnection(FailurePolicy::Log);
    }

    public function testACreationOfAJoinedEntityIsOneExecutionOfBothItsTables(): void
    {
        $this->begin();
        $this->em->persist($press = new Press('One'));
        $this->em->flush();

        $runs = $this->runs();

        self::assertSame([['create', 'machine', $press->id, ['name' => [null, 'One'], 'tonnage' => [null, 1]]]], self::said($runs));
        self::assertCount(2, $runs[0]['at'], 'two statements, one creation');
        self::assertSame($press, $runs[0]['entity']);

        $this->end();
    }

    public function testAChangeOfBothTablesOfAJoinedRowInOneFlushIsOneExecution(): void
    {
        $this->em->persist($press = new Press('One'));
        $this->em->flush();

        $this->begin();
        $press->name = 'Two';
        $press->tonnage = 5;
        $this->em->flush();

        self::assertSame([['update', 'machine', $press->id, ['name' => ['One', 'Two'], 'tonnage' => [1, 5]]]], self::said($this->runs()));

        $this->end();
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function whetherTheApplicationWritesBetween(): iterable
    {
        yield 'the two changes one after the other' => [false, false];
        yield 'a statement of the application\'s between them' => [true, false];
        yield 'one after the other, in a transaction of the application\'s' => [false, true];
        yield 'a statement between them, in a transaction of the application\'s' => [true, true];
    }

    /**
     * The outer flush changes the root's column; a postUpdate listener runs a nested flush that
     * changes the subclass's. Without savepoints the two UPDATEs are of one row, of the
     * hierarchy's two tables, in one frame, under one owner -- measured, and asserted below as
     * the premise -- and, with nothing between them, next to each other in the log: where the
     * nested flush began is what says they are two. With the application's statement between
     * them they are not next to each other in the log, though they are among the facts.
     *
     * Inside a transaction of the application's the flushes are owned apart, and that has to
     * say the same; outside one, what the listener remembers is settled once the flush is over,
     * so the facts are read by a replay of its own over the rows as they were before.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('whetherTheApplicationWritesBetween')]
    public function testTwoChangesOfAJoinedRowEachOfOneTableAreTwoExecutionsWithoutSavepoints(bool $between, bool $inATransaction): void
    {
        $this->unownedStatementsAreExpected = true;
        $this->log = $this->watchTheConnection(FailurePolicy::Log, savepoints: false);
        $this->em->persist($vehicle = new Vehicle());
        $this->em->persist($press = new Press('One'));
        $this->em->flush();

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $press, $between ? $vehicle->id : null) {
            private bool $ran = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Press $press, private readonly mixed $vehicle)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() !== $this->press || $this->ran) {
                    return;
                }

                $this->ran = true;

                if ($this->vehicle !== null) {
                    $this->em->getConnection()->update('Vehicle', ['plate' => 'between'], ['id' => $this->vehicle]);
                }

                $this->press->tonnage = 5;
                $this->em->flush();
            }
        });

        $replay = null;

        if ($inATransaction) {
            $this->begin();
        } else {
            $this->from = $this->log->position();
            $replay = new HistoryReplay($this->em, $this->memory()->rows(), $this->memory()->takenAt());
        }

        $press->name = 'Two';
        $this->em->flush();

        $replay?->replay($this->log, $this->from);
        $runs = $this->runs($replay);

        self::assertSame([
            ['update', 'machine', $press->id, ['name' => ['One', 'Two']]],
            ['update', 'machine', $press->id, ['tonnage' => [1, 5]]],
        ], self::said($runs));

        $connection = $this->em->getConnection();

        if (!$inATransaction && method_exists($connection, 'getNestTransactionsWithSavepoints') && !$connection->getNestTransactionsWithSavepoints()) {
            $outer = $runs[0]['at'][0];
            $nested = $runs[1]['at'][0];

            self::assertSame([$this->log->frameOf($outer), $this->log->ownerOf($outer)], [$this->log->frameOf($nested), $this->log->ownerOf($nested)], 'the premise: one frame, one owner');
            self::assertSame($between ? $outer + 2 : $outer + 1, $nested, 'the premise: next to each other in the log, or the application\'s statement between');
        }

        if ($inATransaction) {
            $this->end();
        }
    }

    public function testTheSameTableOfARowWrittenTwiceInARowIsTwoExecutions(): void
    {
        // The flush's UPDATE, and right after it -- in its frame, so its flush's -- a statement
        // of a postUpdate listener's writing the same column of the same row: next to each
        // other, of one kind and one row. One table cannot be two tables of one change.
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $article) {
            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly Article $article)
            {
            }

            public function postUpdate(LifecycleEventArgs $args): void
            {
                if ($args->getObject() === $this->article) {
                    $this->em->getConnection()->update('Article', ['title' => 'By hand'], ['id' => $this->article->id]);
                }
            }
        });

        $this->begin();
        $article->title = 'Hello again';
        $this->em->flush();

        $runs = $this->runs();

        self::assertSame([
            ['update', 'article', $article->id, ['status' => ['draft', 'draft'], 'title' => ['Hello', 'Hello again']]],
            ['update', 'article', $article->id, ['status' => ['draft', 'draft'], 'title' => ['Hello again', 'By hand']]],
        ], self::said($runs));
        self::assertSame($runs[0]['at'][0] + 1, $runs[1]['at'][0], 'the premise: next to each other in the log');

        $this->end();
    }

    public function testAManyToOneIsTheEntityItNamesAsTheRepresenterHasIt(): void
    {
        $this->em->persist($ada = new Author('Ada'));
        $this->em->persist($bea = new Author('Bea'));
        $article = new Article('Hello');
        $article->author = $ada;
        $this->em->persist($article);
        $this->em->flush();

        $this->begin();
        $article->author = $bea;
        $this->em->flush();

        self::assertSame([['update', 'article', $article->id, ['author' => ['Ada', 'Bea'], 'status' => ['draft', 'draft']]]], self::said($this->runs()));

        $this->end();
    }

    public function testAManyToOneNamedByAManagerThatNoLongerHoldsItIsAReference(): void
    {
        // Cleared between: the author the row named is not in the manager, and the change says
        // who it was all the same, through a reference -- by its id, whatever form the column has.
        $this->em->persist($ada = new Author('Ada'));
        $this->em->persist($bea = new Author('Bea'));
        $article = new Article('Hello');
        $article->author = $ada;
        $this->em->persist($article);
        $this->em->flush();
        $this->em->clear();

        $this->begin();
        $article = $this->em->find(Article::class, $article->id);
        self::assertInstanceOf(Article::class, $article);
        $article->author = $this->em->find(Author::class, $bea->id);
        $this->em->flush();

        self::assertSame([['update', 'article', $article->id, ['author' => ['Ada', 'Bea'], 'status' => ['draft', 'draft']]]], self::said($this->runs()));

        $this->end();
    }

    public function testTheContextBesideAChangeIsTheRowsAndNotWhatTheObjectMovedToAfter(): void
    {
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

        $this->begin();
        $article->title = 'Hello again';
        $this->em->flush();

        self::assertSame([['update', 'article', $article->id, ['status' => ['draft', 'draft'], 'title' => ['Hello', 'Hello again']]]], self::said($this->runs()));

        $this->end();
    }

    public function testAnUpdateOfNothingAuditedIsAnExecutionThatSaysNothing(): void
    {
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();

        $this->begin();
        $article->views = 7;
        $this->em->flush();

        self::assertSame([['update', 'article', $article->id, []]], self::said($this->runs()), 'whether to write it is the listener\'s: skip_empty_updates');

        $this->end();
    }

    public function testARemovalIsTheRowGoingAndSaysNothingMore(): void
    {
        $this->em->persist($beacon = new Beacon());
        $this->em->persist($press = new Press('One'));
        $this->em->flush();
        $beaconId = $beacon->id;
        $pressId = $press->id;

        $this->begin();
        $this->em->remove($beacon);
        $this->em->remove($press);
        $this->em->flush();

        $said = self::said($this->runs());
        usort($said, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));

        self::assertEquals([['remove', 'beacon', $beaconId, []], ['remove', 'machine', $pressId, []]], $said);

        $this->end();
    }

    public function testWhatTheApplicationRanOutsideEveryFlushIsNoExecution(): void
    {
        $this->unownedStatementsAreExpected = true;
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();

        $this->begin();
        $this->em->getConnection()->update('Article', ['title' => 'By hand'], ['id' => $article->id]);

        self::assertSame([], self::said($this->runs()));
        self::assertNotSame([], $this->memory()->replayed($this->em)->rowFacts(), 'the premise: the statement is a fact of the row');

        $this->end();
    }

    public function testOnlyWhatRanAfterThePointReadThroughIsRead(): void
    {
        $this->begin();
        $this->em->persist($article = new Article('Hello'));
        $this->em->flush();
        $this->from = $this->log->position();
        $article->title = 'Hello again';
        $this->em->flush();

        self::assertSame([['update', 'article', $article->id, ['status' => ['draft', 'draft'], 'title' => ['Hello', 'Hello again']]]], self::said($this->runs()));

        $this->end();
    }

    /** A transaction of the application's own around what follows, and where the log stands. */
    private function begin(): void
    {
        $this->em->getConnection()->beginTransaction();
        $this->from = $this->log->position();
    }

    /** The scenario's transaction closed, once its facts are read. */
    private function end(): void
    {
        $this->em->getConnection()->rollBack();
    }

    /**
     * @return list<array{event: string, class: class-string, entity: object|null, objectType: string, id: int|string, flush: int, at: list<int>, changes: array<string, Change>}>
     */
    private function runs(?HistoryReplay $replay = null): array
    {
        $listener = $this->listener();
        $identity = new RowIdentity(
            (new \ReflectionMethod(AuditSubscriber::class, 'identifierOf'))->getClosure($listener),
            (new \ReflectionMethod(AuditSubscriber::class, 'identifierFrom'))->getClosure($listener),
        );

        return (new EntityRowRuns(new AuditMetadataFactory(), new ValueComparator(), $identity))
            ->of($this->em, $replay ?? $this->memory()->replayed($this->em), $this->log, $this->from);
    }

    /**
     * The fields in no particular order: they come in the order the declaration names them,
     * which is the builder's, not the reader's.
     *
     * @param list<array{event: string, objectType: string, id: int|string, changes: array<string, Change>}> $runs
     *
     * @return list<array{0: string, 1: string, 2: int|string, 3: array<string, array{0: mixed, 1: mixed}>}>
     */
    private static function said(array $runs): array
    {
        return array_map(static function (array $run): array {
            $changes = array_map(static fn (Change $change): array => [$change->old, $change->new], $run['changes']);
            ksort($changes);

            return [$run['event'], $run['objectType'], $run['id'], $changes];
        }, $runs);
    }

    private function memory(): RowMemory
    {
        $memory = (new \ReflectionProperty(AuditSubscriber::class, 'rows'))->getValue($this->listener());
        self::assertInstanceOf(RowMemory::class, $memory);

        return $memory;
    }

    private function listener(): AuditSubscriber
    {
        foreach ($this->em->getEventManager()->getListeners(Events::onFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                return $listener;
            }
        }

        self::fail('the premise: the listener is attached');
    }
}
