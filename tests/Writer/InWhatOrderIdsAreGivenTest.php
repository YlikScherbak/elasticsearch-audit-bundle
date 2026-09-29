<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailureDetails;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IdSequence;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Borsche\ElasticsearchAuditBundle\Writer\Provenance;
use Borsche\ElasticsearchAuditBundle\Writer\RecordId;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

/**
 * The ids the writer hands out (1.3 C): within a millisecond of one process, in the order the
 * records were built -- one moment's, and every moment's of that millisecond alive at once,
 * however far apart their writing is.
 */
final class InWhatOrderIdsAreGivenTest extends TestCase
{
    private InMemoryGateway $gateway;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testOneMomentsRecordsSortByIdInTheOrderTheyWereBuilt(): void
    {
        $writer = $this->writer();
        $writer->writeAll(array_map(static fn (int $i): AuditRecord => new AuditRecord('order', $i, AuditEvent::UPDATE), range(1, 50)), $writer->provenance());

        $this->assertSortedAsWritten();
    }

    public function testMomentsOfOneMillisecondAliveTogetherShareTheCounter(): void
    {
        // A flush and one nested in it, both in the same millisecond, their records written as
        // the outer flush publishes them: the outer's first, the nested one's, the outer's last
        // -- t1, t2, t1 in one millisecond.
        $writer = $this->writer();
        $outer = $writer->provenance();
        $nested = $writer->provenance();

        self::assertSame($outer->ids, $nested->ids, 'one counter for the millisecond');

        // Twelve, turn and turn about: ids random in the millisecond would be in this order one
        // time in twelve factorial.
        for ($i = 1; $i <= 12; ++$i) {
            $writer->writeAll([new AuditRecord('order', $i, AuditEvent::UPDATE)], $i % 2 === 1 ? $outer : $nested);
        }

        $this->assertSortedAsWritten();
    }

    public function testAMomentKeepsItsCounterHoweverManyMillisecondsComeBetween(): void
    {
        // A flush whose publishing was swallowed has its records written later, by another
        // flush, dated where they happened. Its moment writes a record; a thousand moments of
        // other milliseconds come and go -- more than any cache of recent ones would hold; then it
        // writes another. Every counter begins below the ones before it, so a
        // counter begun again for it would sort its second record before its first: certainly,
        // not one time in two.
        $writer = $this->writer(new TickingClock(new \DateTimeImmutable('2026-08-26 12:00:00.000', new \DateTimeZone('UTC'))));
        $this->beginningEachBelowTheLast($writer);
        $late = $writer->provenance();
        $writer->writeAll([new AuditRecord('late', 1, AuditEvent::UPDATE)], $late);

        for ($i = 1; $i <= 1000; ++$i) {
            $writer->writeAll([new AuditRecord('noise', $i, AuditEvent::UPDATE)], $writer->provenance());
        }

        $writer->writeAll([new AuditRecord('late', 1, AuditEvent::UPDATE)], $late);

        $ids = array_column(array_values(array_filter($this->gateway->documents['audit_log'], static fn (array $d): bool => $d['objectType'] === 'late')), 'id');
        self::assertCount(2, $ids);
        self::assertLessThan(0, strcmp($ids[0], $ids[1]), 'the late record sorts after its moment\'s first');
    }

    public function testACounterNoMomentHoldsIsLetGo(): void
    {
        // Weakly held: the moments keep a counter, the writer does not -- a worker writing for a
        // week does not keep one for every millisecond it has seen.
        $writer = $this->writer(new TickingClock(new \DateTimeImmutable('2026-08-26 12:00:00.000', new \DateTimeZone('UTC'))));

        for ($i = 1; $i <= 100; ++$i) {
            $writer->writeAll([new AuditRecord('order', $i, AuditEvent::UPDATE)], $writer->provenance());
        }

        $held = (new \ReflectionProperty(AuditWriter::class, 'sequences'))->getValue($writer);
        self::assertIsArray($held);
        self::assertLessThanOrEqual(1, \count(array_filter($held, static fn (\WeakReference $one): bool => $one->get() !== null)), 'no counter outlives the moments that held it');
        self::assertLessThanOrEqual(2, \count($held), 'and the dead ones are cleared as new ones come');
    }

    public function testTwoMomentsOneAfterTheOtherInOneMillisecondContinueOneCounter(): void
    {
        // Two flushes one after the other, fast: the first's moment is let go of once its records
        // are handed on -- into a frame, say -- before the second's is settled, in the same
        // millisecond. Every counter begins below the ones before it: a second begun for the
        // second flush would sort its record before the first's, certainly.
        $writer = $this->writer(new FrozenClock(new \DateTimeImmutable('2026-08-26 12:00:00.000', new \DateTimeZone('UTC'))));
        $this->beginningEachBelowTheLast($writer);

        $writer->writeAll([new AuditRecord('pair', 1, AuditEvent::UPDATE)], $writer->provenance());
        $writer->writeAll([new AuditRecord('pair', 2, AuditEvent::UPDATE)], $writer->provenance());

        $this->assertSortedAsWritten();
    }

    public function testACounterThatRunsOutCostsTheRecordThroughThePolicyAndNotTheOthers(): void
    {
        // Past 2^41 ids of one millisecond: the record gets no id that could sort before the
        // others, and its failure goes the way any record's that cannot be completed does.
        $at = new \DateTimeImmutable('2026-08-26 12:00:00.000', new \DateTimeZone('UTC'));
        $moment = new Provenance($at, null, [], new IdSequence(RecordId::millisecondOf($at), 2 ** 42 - 1));

        $this->writer()->writeAll([new AuditRecord('order', 1, AuditEvent::UPDATE), new AuditRecord('order', 2, AuditEvent::UPDATE)], $moment);

        self::assertSame([1], array_column($this->gateway->documents['audit_log'], 'objectId'), 'the one that got an id is written');
        $failed = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof RecordFailedEvent));
        self::assertCount(1, $failed);
        self::assertInstanceOf(\OverflowException::class, $failed[0]->reason);
    }

    public function testARecordAFrameMergedKeepsTheIdOfItsFirstPart(): void
    {
        // An order changed twice and a line once, inside a frame: the order's two records are
        // one, with the id the first was given; the line's sorts after it, as it was built.
        $buffer = new FrameBuffer();
        $writer = $this->writer(buffer: $buffer);
        $frame = new AuditFrame($buffer, $writer, new NullLogger());
        $moment = $writer->provenance();
        $given = [];

        $frame->coalesce(function () use ($writer, $moment, &$given): void {
            $writer->writeAll([new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('new', 'paid')])], $moment);
            $given[] = $this->idOfTheLastRecordCompleted($moment);
            $writer->writeAll([new AuditRecord('line', 7, AuditEvent::UPDATE, changes: ['quantity' => new Change(1, 2)])], $moment);
            $writer->writeAll([new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('paid', 'shipped')])], $moment);
        });

        $documents = $this->gateway->documents['audit_log'];
        self::assertSame([['order', ['old' => 'new', 'new' => 'shipped']], ['line', null]], array_map(static fn (array $d): array => [$d['objectType'], $d['changes']['status'] ?? null], $documents), 'the premise: merged');
        self::assertLessThan(0, strcmp($documents[0]['id'], $documents[1]['id']), 'the merged record sorts where its first part was built');
        self::assertSame($given[0], substr($documents[0]['id'], 0, 28), 'and has the id its first part was given: the millisecond and the counter, all but the random tail');
    }

    /** The id the counter of a moment gave last: the one its next id comes right after. */
    private function idOfTheLastRecordCompleted(Provenance $moment): string
    {
        // Read back from the counter rather than from the record, which the frame is holding:
        // the next value, one down, packed as RecordId packs it.
        self::assertNotNull($moment->ids);
        $next = (new \ReflectionProperty(IdSequence::class, 'next'))->getValue($moment->ids);
        self::assertIsInt($next);
        $probe = new IdSequence($moment->ids->millisecond, $next - 1);

        return substr(RecordId::v7($moment->at, $probe), 0, 28);
    }

    /**
     * Every counter the writer begins begins below every one before it, a million apart: one
     * begun again for a millisecond that had one sorts what it gives before what the first gave,
     * whatever came between -- never by the luck of a random start.
     */
    private function beginningEachBelowTheLast(AuditWriter $writer): void
    {
        $begun = 0;
        (new \ReflectionProperty(AuditWriter::class, 'startOf'))->setValue($writer, static function (int $ms) use (&$begun): int {
            return 2 ** (IdSequence::BITS - 1) - 1_000_000 * ++$begun;
        });
    }

    private function assertSortedAsWritten(): void
    {
        $ids = array_column($this->gateway->documents['audit_log'], 'id');
        $sorted = $ids;
        sort($sorted, \SORT_STRING);

        self::assertGreaterThan(1, \count($ids));
        self::assertSame($ids, $sorted, 'sorted by id as a string, the records are in the order they were written');
    }

    private function writer(?ClockInterface $clock = null, FailurePolicy $policy = FailurePolicy::Log, ?FrameBuffer $buffer = null): AuditWriter
    {
        $events = &$this->events;
        $dispatcher = new class($events) implements EventDispatcherInterface {
            /** @param list<object> $events */
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), $clock ?? new FrozenClock(), [], $policy, null, $dispatcher, $buffer, null, 500, FailureDetails::Full);
    }
}

/** A clock one millisecond on each time it is asked. */
final class TickingClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        $now = $this->now;
        $this->now = $now->modify('+1 msec');

        return $now;
    }
}
