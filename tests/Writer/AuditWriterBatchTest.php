<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Many records, one request: what writeAll() does with a flush's worth of records,
 * and what happens when the cluster refuses some of them.
 */
final class AuditWriterBatchTest extends TestCase
{
    private InMemoryGateway $gateway;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testAFlushsWorthOfRecordsIsOneBulkRequest(): void
    {
        $this->writer()->writeAll([
            new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]),
            new AuditRecord('order', 2, AuditEvent::CREATE),
            new AuditRecord('auth', 'alice', 'login_failed'),
        ]);

        self::assertCount(1, $this->gateway->bulks, 'one request for the whole batch');
        self::assertSame(['audit_log', 'audit_log', 'audit_auth'], array_column($this->gateway->bulks[0], 'index'), 'routing applies per item');
        self::assertCount(2, $this->gateway->documents['audit_log']);
        self::assertCount(1, $this->gateway->documents['audit_auth']);
        self::assertSame('system', $this->gateway->documents['audit_log'][0]['source'], 'every record was completed');
        self::assertNotNull($this->gateway->bulks[0][0]['id'], 'and carries its id, so a retry overwrites instead of duplicating');
    }

    public function testNothingToWriteMeansNoRequest(): void
    {
        $this->writer()->writeAll([]);

        self::assertSame([], $this->gateway->bulks);
    }

    public function testAVetoedRecordLeavesTheBatchAndTheOthersStillGo(): void
    {
        $listener = static function (object $event): void {
            if ($event instanceof RecordCreatedEvent && $event->getRecord()->event === 'heartbeat') {
                $event->veto();
            }
        };

        $this->writer(listener: $listener)->writeAll([
            new AuditRecord('order', 1, 'heartbeat'),
            new AuditRecord('order', 2, AuditEvent::UPDATE),
        ]);

        self::assertCount(1, $this->gateway->bulks[0]);
        self::assertSame(2, $this->gateway->only('audit_log')['objectId']);
    }

    public function testARefusedItemIsReportedOnItsOwnUnderTheLogPolicy(): void
    {
        $this->gateway->rejectInBulk = static fn (array $document) => $document['objectId'] === 2;

        $this->writer()->writeAll([
            new AuditRecord('order', 1, AuditEvent::UPDATE),
            new AuditRecord('order', 2, AuditEvent::UPDATE),
            new AuditRecord('order', 3, AuditEvent::UPDATE),
        ]);

        self::assertSame([1, 3], array_column($this->gateway->documents['audit_log'], 'objectId'), 'the others were written');

        $failed = array_values(array_filter($this->events, static fn (object $e) => $e instanceof RecordFailedEvent));
        self::assertCount(1, $failed);
        self::assertSame(2, $failed[0]->record->objectId);
        self::assertStringContainsString('rejected by the test', $failed[0]->reason->getMessage());
    }

    public function testUnderTheThrowPolicyEveryFailureIsReportedBeforeTheFirstIsThrown(): void
    {
        $this->gateway->rejectInBulk = static fn (array $document) => $document['objectId'] !== 2;

        try {
            $this->writer(FailurePolicy::Throw)->writeAll([
                new AuditRecord('order', 1, AuditEvent::UPDATE),
                new AuditRecord('order', 2, AuditEvent::UPDATE),
                new AuditRecord('order', 3, AuditEvent::UPDATE),
            ]);
            self::fail('expected WriteFailedException');
        } catch (WriteFailedException $e) {
            self::assertSame(1, $e->record?->objectId, 'the first failure is the one thrown');
        }

        $failed = array_filter($this->events, static fn (object $e) => $e instanceof RecordFailedEvent);
        self::assertCount(2, $failed, 'both refused records were reported, not just the thrown one');
        self::assertSame([2], array_column($this->gateway->documents['audit_log'], 'objectId'));
    }

    public function testAWholeBatchFailingFailsEveryRecord(): void
    {
        $this->gateway->failWith = new \RuntimeException('cluster down');

        $this->writer()->writeAll([
            new AuditRecord('order', 1, AuditEvent::UPDATE),
            new AuditRecord('order', 2, AuditEvent::UPDATE),
        ]);

        $failed = array_filter($this->events, static fn (object $e) => $e instanceof RecordFailedEvent);
        self::assertCount(2, $failed);
    }

    public function testATransportThatCannotBatchGetsTheRecordsOneByOne(): void
    {
        $plain = new class implements TransportInterface {
            /** @var list<string> */
            public array $sent = [];

            public function send(string $index, array $document, ?string $id = null): void
            {
                $this->sent[] = $index;
            }
        };

        $writer = new AuditWriter($plain, $plain, new IndexResolver('audit_log'), new ChainActorResolver([]), new FrozenClock());
        $writer->writeAll([new AuditRecord('order', 1, AuditEvent::UPDATE), new AuditRecord('order', 2, AuditEvent::UPDATE)]);

        self::assertSame(['audit_log', 'audit_log'], $plain->sent);
    }

    public function testInsideAFrameTheBatchIsHeldAndTheFrameWritesOneBulk(): void
    {
        $buffer = new FrameBuffer();
        $writer = $this->writer(buffer: $buffer);
        $frame = new AuditFrame($buffer, $writer);

        $frame->coalesce(function () use ($writer): void {
            $writer->writeAll([new AuditRecord('stock', 1, AuditEvent::UPDATE, changes: ['fact' => new Change(1, 2)])]);
            $writer->writeAll([new AuditRecord('stock', 1, AuditEvent::UPDATE, changes: ['fact' => new Change(2, 3)]), new AuditRecord('stock', 2, AuditEvent::UPDATE, changes: ['fact' => new Change(5, 6)])]);

            self::assertSame([], $this->gateway->bulks, 'nothing leaves while the frame is open');
        });

        self::assertCount(1, $this->gateway->bulks, 'the frame released everything in one request');
        self::assertCount(2, $this->gateway->bulks[0]);
        self::assertSame(['old' => 1, 'new' => 3], $this->gateway->documents['audit_log'][0]['changes']['fact']);
    }

    public function testEveryChunkIsReportedBeforeTheFirstFailureIsThrown(): void
    {
        // The promise of the unchunked days holds across chunks: with "throw" the
        // exception comes after every record's failure was logged and dispatched.
        $this->gateway->failWith = new \RuntimeException('down');
        $records = [];

        for ($i = 0; $i < 5; ++$i) {
            $records[] = new AuditRecord('order', $i, 'update', changes: ['status' => new Change('a', 'b')]);
        }

        try {
            $this->writer(FailurePolicy::Throw, batchSize: 2)->writeAll($records);
            self::fail('expected WriteFailedException');
        } catch (WriteFailedException $e) {
            self::assertSame(0, $e->record?->objectId, 'the first failure is the one thrown');
        }

        $failed = array_filter($this->events, static fn (object $e) => $e instanceof RecordFailedEvent);

        self::assertCount(5, $failed, 'the chunks after the first failure were still tried and their records reported');
    }

    public function testOneRecordThatCannotBePreparedDoesNotTakeItsWholeChunkDown(): void
    {
        // A broken enricher fails one record while it is being prepared, and under
        // "throw" the report threw from inside the preparation loop: the records
        // prepared before it never reached the bulk request, and the ones after it were
        // never prepared at all. A hole the size of batch_size, in the policy chosen
        // because a missing entry is unacceptable.
        $records = [];

        for ($i = 0; $i < 4; ++$i) {
            $records[] = new AuditRecord('order', $i, 'update', changes: ['status' => new Change('a', 'b')]);
        }

        $enricher = new class implements \Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                if ($record->objectId === 1) {
                    throw new \RuntimeException('the enricher is broken for this one');
                }

                return $record;
            }

            public function mapping(): array
            {
                return [];
            }
        };

        try {
            $this->writer(FailurePolicy::Throw, batchSize: 4, enrichers: [$enricher])->writeAll($records);
            self::fail('expected WriteFailedException');
        } catch (WriteFailedException $e) {
            self::assertSame(1, $e->record?->objectId, 'the record that could not be prepared is the one thrown');
        }

        $written = array_column($this->gateway->documents['audit_log'] ?? [], 'objectId');
        sort($written);

        self::assertSame([0, 2, 3], $written, 'every record that could be prepared was still written');
    }

    public function testANonBatchTransportStillAttemptsEveryRecordBeforeThrowing(): void
    {
        // The guarantee must not depend on whether a transport happens to implement an
        // optional interface: with "throw", the batch path reports every failure and
        // raises the first, and the one-by-one fallback stopped at the first — leaving
        // the rest of a flush unattempted, and, when a frame had just drained into it,
        // gone.
        $plain = new class implements TransportInterface {
            /** @var list<int|string> */
            public array $attempted = [];

            public function send(string $index, array $document, ?string $id = null): void
            {
                $this->attempted[] = $document['objectId'];

                throw new \RuntimeException('cluster down');
            }
        };

        $writer = new AuditWriter($plain, $plain, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Throw);

        try {
            $writer->writeAll([
                new AuditRecord('order', 1, AuditEvent::UPDATE),
                new AuditRecord('order', 2, AuditEvent::UPDATE),
                new AuditRecord('order', 3, AuditEvent::UPDATE),
            ]);
            self::fail('expected WriteFailedException');
        } catch (WriteFailedException $e) {
            self::assertSame(1, $e->record?->objectId, 'the first failure is the one thrown');
        }

        self::assertSame([1, 2, 3], $plain->attempted, 'every record was tried');
    }

    public function testASingleFailureReportsTheRecordThatWasActuallySent(): void
    {
        // prepare() may replace the record entirely — merged enrichers, then a listener
        // on RecordCreatedEvent. Reporting the original means the failure event names an
        // object type and attributes that were never sent, which is what monitoring and
        // any retry built on it would act upon.
        $listener = static function (object $event): void {
            if ($event instanceof RecordCreatedEvent) {
                $event->setRecord($event->getRecord()->withAttributes(['securityLevel' => 5]));
            }
        };

        $this->gateway->failWith = new \RuntimeException('cluster down');

        $plain = new class($this->gateway) implements TransportInterface {
            public function __construct(private readonly InMemoryGateway $gateway)
            {
            }

            public function send(string $index, array $document, ?string $id = null): void
            {
                $this->gateway->index($index, $document, $id);
            }
        };

        $writer = $this->writerWith($plain, FailurePolicy::Log, $listener);
        $writer->write(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]));

        $failed = array_values(array_filter($this->events, static fn (object $e) => $e instanceof RecordFailedEvent));

        self::assertCount(1, $failed);
        self::assertSame(5, $failed[0]->record->attributes['securityLevel'] ?? null, 'the record reported is the one that was sent');
    }

    public function testAReplacementWithoutAnIdCannotReachTheTransport(): void
    {
        // A listener may hand back a whole new record, and a record without an id is
        // stored again under a generated one on every redelivery. The batch path
        // refused it already; the single path passed null straight through.
        $listener = static function (object $event): void {
            if ($event instanceof RecordCreatedEvent) {
                // With a timestamp, or toDocument() would refuse it for that instead and
                // the test would pass without proving anything about the id.
                $event->setRecord(new AuditRecord('order', 10, AuditEvent::UPDATE, new \DateTimeImmutable('2026-09-05 10:00:00', new \DateTimeZone('UTC')), 'system', ['x' => new Change(1, 2)]));
            }
        };

        $seen = [];
        $plain = new class($seen) implements TransportInterface {
            /** @param list<?string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function send(string $index, array $document, ?string $id = null): void
            {
                $this->seen[] = $id;
            }
        };

        $writer = $this->writerWith($plain, FailurePolicy::Log, $listener);
        $writer->write(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]));

        self::assertSame([], $seen, 'nothing was sent without an id');

        $failed = array_values(array_filter($this->events, static fn (object $e) => $e instanceof RecordFailedEvent));

        self::assertCount(1, $failed, 'and the record was reported as failed rather than silently duplicated later');
    }

    public function testAnOverflowingFrameRefusesTheOperationWhateverTheFailurePolicy(): void
    {
        // on_overflow: throw is a deliberate refusal, not a failed write: it must reach
        // the operation even under on_failure: log, and the records must not be
        // reported as failures — nothing was tried.
        $buffer = new FrameBuffer(maxHeld: 2, throwOnOverflow: true);
        $writer = $this->writer(buffer: $buffer);
        $frame = new AuditFrame($buffer, $writer);

        $records = [];

        for ($i = 1; $i <= 4; ++$i) {
            $records[] = new AuditRecord('stock', $i, 'update', changes: ['q' => new Change(1, 2)]);
        }

        $frame->begin();

        try {
            $writer->writeAll($records);
            self::fail('expected FrameOverflowException');
        } catch (FrameOverflowException) {
        }

        self::assertSame([], array_filter($this->events, static fn (object $e) => $e instanceof RecordFailedEvent), 'a refusal is not a failure of any one record');

        $frame->reset();
    }

    public function testAnOverflowReachesASingleWriteToo(): void
    {
        $buffer = new FrameBuffer(maxHeld: 1, throwOnOverflow: true);
        $writer = $this->writer(buffer: $buffer);
        $frame = new AuditFrame($buffer, $writer);

        $frame->begin();
        $writer->record('stock', 1, AuditEvent::UPDATE, ['q' => new Change(1, 2)]);

        $this->expectException(FrameOverflowException::class);

        try {
            $writer->record('stock', 2, AuditEvent::UPDATE, ['q' => new Change(1, 2)]);
        } finally {
            $frame->reset();
        }
    }

    public function testAFlushLargerThanTheBatchSizeIsSplit(): void
    {
        // One _bulk body and one Messenger payload both have a size somebody has to
        // choose, and a request refused for being too large loses every record in it.
        $records = [];

        for ($i = 0; $i < 5; ++$i) {
            $records[] = new AuditRecord('order', $i, 'update', changes: ['status' => new Change('a', 'b')]);
        }

        $this->writer(batchSize: 2)->writeAll($records);

        self::assertSame([2, 2, 1], array_map('count', $this->gateway->bulks), 'three requests, in the order the records were made');
        self::assertCount(5, $this->gateway->documents['audit_log'], 'and every record went');
    }

    private function writerWith(TransportInterface $transport, FailurePolicy $policy, ?callable $listener = null): AuditWriter
    {
        $events = &$this->events;
        $dispatcher = new class($events, $listener) implements EventDispatcherInterface {
            /**
             * @param list<object>                  $events
             * @param (callable(object): void)|null $listener
             */
            public function __construct(private array &$events, private $listener)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                if ($this->listener !== null) {
                    ($this->listener)($event);
                }

                return $event;
            }
        };

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], $policy, null, $dispatcher);
    }

    /**
     * @param list<\Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface> $enrichers
     */
    private function writer(FailurePolicy $policy = FailurePolicy::Log, ?callable $listener = null, ?FrameBuffer $buffer = null, int $batchSize = 500, array $enrichers = []): AuditWriter
    {
        $events = &$this->events;
        $dispatcher = new class($events, $listener) implements EventDispatcherInterface {
            /**
             * @param list<object>                  $events
             * @param (callable(object): void)|null $listener
             */
            public function __construct(private array &$events, private $listener)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                if ($this->listener !== null) {
                    ($this->listener)($event);
                }

                return $event;
            }
        };

        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log', ['auth' => 'audit_auth']), new ChainActorResolver([], 'system'), new FrozenClock(), $enrichers, $policy, null, $dispatcher, $buffer, null, $batchSize);
    }
}
