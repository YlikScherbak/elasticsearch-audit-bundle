<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MomentEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;

/**
 * The writer at its edges: the default batch, what a failed batch reports, when a frame's
 * comparator failures are told, the moment's enrichers and its counter, and what a failure that
 * could not even be redacted says.
 */
final class WhatTheWriterPromisesAtItsEdgesTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private InMemoryGateway $gateway;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->logs = [];
        $this->gateway = new InMemoryGateway();
        $this->now = new \DateTimeImmutable('2026-10-03 12:00:00.001', new \DateTimeZone('UTC'));
    }

    /** @return iterable<string, array{int, int}> */
    public static function batches(): iterable
    {
        yield 'five hundred: one request' => [500, 1];
        yield 'five hundred and one: two' => [501, 2];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('batches')]
    public function testTheDefaultBatchIsFiveHundredRecords(int $records, int $requests): void
    {
        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), $this->clock());

        $writer->writeAll(array_map(static fn (int $i): AuditRecord => new AuditRecord('order', $i, AuditEvent::CREATE), range(1, $records)));

        self::assertCount($requests, $this->gateway->bulks);
    }

    public function testABatchThatFailsWholeReportsTheRecordsThatNeverReachedItToo(): void
    {
        // One record could not be prepared for the request -- redaction refused it -- and the
        // request for the rest failed: both are reported, the one never sent among them.
        $this->gateway->failWith = new \RuntimeException('the cluster is down');

        $this->writer([], redactor: new ChangeRedactor(['password'], maxNodes: 2))->writeAll([
            new AuditRecord('order', 'too big', AuditEvent::UPDATE, changes: ['a' => 1, 'b' => 2, 'c' => 3]),
            new AuditRecord('order', 'fine', AuditEvent::CREATE),
        ]);

        self::assertCount(2, $this->said('Audit record could not be written'));
    }

    public function testNothingToSendIsNoRequest(): void
    {
        // A frame closing on nothing, and a batch every record of which a listener vetoed: the
        // transport is not handed an empty batch -- an asynchronous one would queue a message
        // of no records for every request a frame wrapped.
        $transport = new CountingBatchTransport();
        $frame = new FrameBuffer();
        $events = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $events->addListener(\Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent::class, static function (\Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent $event): void {
            $event->veto();
        });
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), $this->clock(), [], FailurePolicy::Throw, null, $events, $frame);

        (new \Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame($frame, $writer))->coalesce(static fn (): mixed => null);
        $writer->writeAll([new AuditRecord('order', 1, AuditEvent::CREATE)]);

        self::assertSame(0, $transport->batches);
    }

    public function testAComparatorFailureOfARecordLetGoEarlyIsReportedWithIt(): void
    {
        // A remove ends the object's held record early, and its comparator threw: that failure
        // is told where the records go out, not at some later write.
        $frame = new FrameBuffer(new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('the comparator failed');
            }
        });
        $writer = $this->writer([], $frame);
        $frame->open();

        $writer->write(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]));
        self::assertSame([], $this->logs, 'the premise: held, nothing said yet');

        $writer->write(new AuditRecord('order', 1, AuditEvent::REMOVE));

        self::assertNotSame([], $this->logs, 'said with the records that went out');
    }

    public function testEnrichersHandedOverWithTheSameKeyAreEachAsked(): void
    {
        // A generator may give two of them one key; neither is lost to the other.
        $enrichers = (static function (): \Generator {
            yield 'same' => self::describing(['route' => '/checkout']);
            yield 'same' => self::describing(['host' => 'web-1']);
        })();

        $this->writer($enrichers)->record('order', 1, 'updated');

        $document = $this->gateway->only('audit_log');
        self::assertSame(['/checkout', 'web-1'], [$document['route'] ?? null, $document['host'] ?? null]);
    }

    public function testEveryMomentEnricherIsAskedWhateverComesBeforeIt(): void
    {
        $ordinary = new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return false;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record;
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $this->writer([$ordinary, self::describing(['route' => '/checkout']), self::describing(['host' => 'web-1'])])->record('order', 1, 'updated');

        $document = $this->gateway->only('audit_log');
        self::assertSame(['/checkout', 'web-1'], [$document['route'] ?? null, $document['host'] ?? null]);
    }

    public function testAMillisecondMetAgainKeepsItsCounterWhileAMomentOfItLives(): void
    {
        // A moment of another millisecond in between does not let go of the first one's
        // counter: a late flush dated in it takes ids after the ones already given.
        $writer = $this->writer([]);
        $first = $writer->provenance();
        $this->now = $this->now->modify('+5 msec');
        $writer->provenance();
        $this->now = $this->now->modify('-5 msec');
        $again = $writer->provenance();

        self::assertSame($first->ids, $again->ids);
    }

    public function testAFailureThatCannotBeRedactedIsToldWithoutItsRecordAndWithWhatWentWrong(): void
    {
        // The record is refused by redaction -- two places against a budget of one -- and
        // reporting it meets the same wall: it is said without the record, naming why.
        $this->writer([], redactor: new ChangeRedactor(['password'], maxNodes: 1))
            ->write(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['a' => 1, 'b' => 2]));

        $said = $this->said('An audit record could not be redacted while reporting a failure');
        self::assertCount(1, $said);
        self::assertArrayHasKey('reason', $said[0]['context']);
        self::assertArrayHasKey('exception', $said[0]['context']);

        $failed = $this->said('Audit record could not be written');
        self::assertCount(1, $failed);
        self::assertSame([null, null, null], [$failed[0]['context']['objectType'], $failed[0]['context']['objectId'], $failed[0]['context']['event']], 'without its record');
    }

    public function testAFailureIsToldWithTheRecordItWasAbout(): void
    {
        $this->gateway->failWith = new \RuntimeException('the cluster is down');

        $this->writer([])->write(new AuditRecord('order', 7, AuditEvent::UPDATE, changes: ['a' => 1]));

        $failed = $this->said('Audit record could not be written');
        self::assertSame(['order', 7, AuditEvent::UPDATE], [$failed[0]['context']['objectType'] ?? null, $failed[0]['context']['objectId'] ?? null, $failed[0]['context']['event'] ?? null]);
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function said(string $start): array
    {
        return array_values(array_filter($this->logs, static fn (array $log): bool => str_starts_with($log['message'], $start)));
    }

    /**
     * @param iterable<object> $enrichers
     */
    private function writer(iterable $enrichers, ?FrameBuffer $frame = null, ?ChangeRedactor $redactor = null): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), $this->clock(), $enrichers, FailurePolicy::Log, $this->logger(), null, $frame, $redactor);
    }

    private function clock(): ClockInterface
    {
        return new class($this->now) implements ClockInterface {
            public function __construct(private \DateTimeImmutable &$now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }

    /**
     * @param array<string, mixed> $moment
     */
    private static function describing(array $moment): MomentEnricherInterface
    {
        return new class($moment) implements MomentEnricherInterface {
            /** @param array<string, mixed> $moment */
            public function __construct(private readonly array $moment)
            {
            }

            public function describe(): array
            {
                return $this->moment;
            }

            public function mapping(): array
            {
                return array_map(static fn (): array => ['type' => 'keyword'], $this->moment);
            }
        };
    }

    private function logger(): AbstractLogger
    {
        return new class($this->logs) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
