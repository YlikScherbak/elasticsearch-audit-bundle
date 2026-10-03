<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\EntityRowRuns;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\HistoryReplay;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowIdentity;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Doctrine\ORM\Events;

/**
 * What the listener lets go of once it has written the history from it: the key and the fields of
 * every fact of an execution it has made the records of, in the reading that writes them, one
 * execution at a time (HistoryReplay::letGoOf()). A flush of twenty thousand rows held every fact
 * through to the last record.
 *
 * What stays is what the readers of every fact from the first need -- what it was, of which row,
 * where, and the row once it ran: a link's context is the row as it stood at the link's position,
 * however many flushes ago that row's last change was. What goes cannot be read again by accident.
 */
final class WhatTheListenerLetsGoOfTest extends DoctrineTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function whereTheRowChanged(): iterable
    {
        yield 'in the flush before, in the same transaction' => [false];
        yield 'in the same flush, before its links' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whereTheRowChanged')]
    public function testALinksContextIsTheRowAsItStoodThoughItsChangeWasWrittenAndLetGoOf(bool $sameFlush): void
    {
        $tag = new Tag('x');
        $article = new Article('One');
        $this->em->persist($tag);
        $this->em->persist($article);
        $this->em->flush();

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        // The article's status changed and was written -- its fact let go of, as the records of
        // its execution were made -- and then its links moved: a record whose context is the row
        // where the move was, read from what was kept of that fact.
        $article->status = 'published';

        if (!$sameFlush) {
            $this->em->flush();
        }

        // And the object moves on before the history is written -- which the row did not: the
        // context is the row's.
        $this->before(static function () use ($article): void {
            $article->status = 'moved on, in memory';
        });
        $article->tags->add($tag);
        $this->em->flush();
        $connection->commit();

        $moves = array_values(array_filter($this->documents(), static fn (array $d): bool => isset($d['changes']['tags'])));
        self::assertCount(1, $moves, 'the premise: one record of the move');
        $status = $moves[0]['changes']['status'] ?? null;
        self::assertSame('published', \is_array($status) ? ($status['new'] ?? null) : $status, 'the row where the move was');
    }

    public function testWhatWasLetGoOfCannotBeReadAgain(): void
    {
        $article = new Article('One');
        $this->em->persist($article);
        $this->em->flush();

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $article->status = 'published';
        $this->em->flush();

        // The listener's own replay, the transaction still open: what it wrote from is let go.
        $replay = $this->memory()->replayed($this->em);
        $released = array_keys(array_filter($replay->rowFacts(), static fn (array $fact): bool => ($fact['released'] ?? false) === true));
        $connection->rollBack();

        self::assertNotSame([], $released, 'the premise: something was let go of');
        self::assertArrayNotHasKey('fields', $replay->rowFacts()[$released[0]], 'its fields are not kept');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('was let go once its execution was written');
        $replay->rowFactAt($released[0]);
    }

    public function testAReadingThatDoesNotWriteLetsGoOfNothing(): void
    {
        $article = new Article('One');
        $this->em->persist($article);
        $this->em->flush();

        $replay = $this->memory()->replayedApart($this->em, 0);
        $from = $this->statements->position();
        $article->status = 'published';
        $this->em->flush();
        $replay->replay($this->statements, 0);

        $first = $this->runsOf($replay, $from, consume: false);
        $again = $this->runsOf($replay, $from, consume: true);
        $after = array_filter($replay->rowFacts(), static fn (array $fact): bool => $fact['at'] > $from);

        self::assertNotSame([], $first);
        self::assertEquals($first, $again, 'a reading that only counts leaves the next one all of it');
        self::assertSame([true], array_values(array_unique(array_map(static fn (array $fact): bool => ($fact['released'] ?? false) === true, $after))), 'and the reading that writes lets go of what it wrote');
    }

    public function testARecordThatFailsInTheMiddleCostsTheNextFlushNothing(): void
    {
        $refuses = new Relay('refuses');
        $first = new Relay('first');
        $middle = new Relay('middle');
        $last = new Relay('last');

        foreach ([$refuses, $first, $middle, $last] as $relay) {
            $this->em->persist($relay);
        }

        $this->em->flush();
        $created = \count($this->documents());
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        // The flush's history stops in the middle at a record whose representer throws -- the
        // records before it let go of, the ones after it still to come: under on_failure: log,
        // said and left out, and the reading goes on.
        $first->name = 'first, again';
        $middle->next = $refuses;
        $last->name = 'last, again';
        $this->em->flush();
        $written = array_map(static fn (array $d): mixed => $d['changes']['name']['new'] ?? null, \array_slice($this->documents(), $created));

        // The next flush, in the same transaction, over the same replay.
        $first->name = 'first, a third time';
        $this->em->flush();
        $connection->commit();

        self::assertStringContainsString('could not be written: RuntimeException', implode("\n", $this->logs), 'the premise: the record in the middle failed, and was said');
        self::assertSame(['first, again', 'last, again'], array_values(array_filter($written)), 'the records either side of it');
        self::assertSame(['first, again', 'first, a third time'], [$this->lastDocument()['changes']['name']['old'] ?? null, $this->lastDocument()['changes']['name']['new'] ?? null], 'and the next flush\'s record whole, from where the row stood');
    }

    /** @return iterable<string, array{bool}> */
    public static function whatIsLeftOut(): iterable
    {
        yield 'a statement of the application\'s, outside every flush' => [false];
        yield 'a record whose representer failed' => [true];
    }

    /**
     * What the reading that writes passes over is let go of too, not only what it wrote: a
     * statement no flush owns, said and left out, and a record that could not be built. Inside
     * the application's transaction, where nothing settles the rows and the log keeps every
     * statement, a fact held after the flush is one nobody will read again.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('whatIsLeftOut')]
    public function testWhatTheWritingReadingPassesOverIsLetGoOfToo(bool $failing): void
    {
        $this->unownedStatementsAreExpected = true;
        $refuses = new Relay('refuses');
        $relay = new Relay('one');
        $this->em->persist($refuses);
        $this->em->persist($relay);
        $this->em->flush();
        $connection = $this->em->getConnection();
        $from = $this->statements->position();
        $connection->beginTransaction();

        if ($failing) {
            $relay->next = $refuses;
        } else {
            $connection->update('Relay', ['name' => 'the application\'s'], ['id' => $relay->id]);
            $refuses->name = 'refuses, renamed';
        }

        $this->em->flush();
        $held = array_filter($this->memory()->replayed($this->em)->rowFacts(), static fn (array $fact): bool => $fact['at'] > $from && ($fact['released'] ?? false) !== true);
        $connection->rollBack();
        $this->logs = [];

        self::assertSame([], $held);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runsOf(HistoryReplay $replay, int $from, bool $consume): array
    {
        $listener = $this->listener();
        $identity = new RowIdentity(
            (new \ReflectionMethod(AuditSubscriber::class, 'identifierOf'))->getClosure($listener),
            (new \ReflectionMethod(AuditSubscriber::class, 'identifierFrom'))->getClosure($listener),
        );

        return (new EntityRowRuns(new AuditMetadataFactory(), new ValueComparator(), $identity, $this->logger()))
            ->of($this->em, $replay, $this->statements, $from, null, $consume, static fn (int $at): bool => false);
    }

    /** A postFlush listener that runs before the audit listener's. */
    private function before(\Closure $run): void
    {
        $listener = $this->listener();
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, $listener);
        $this->em->getEventManager()->addEventListener([Events::postFlush], new class($run) {
            private bool $ran = false;

            public function __construct(private readonly \Closure $run)
            {
            }

            public function postFlush(): void
            {
                if (!$this->ran) {
                    $this->ran = true;
                    ($this->run)();
                }
            }
        });
        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, $listener);
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
