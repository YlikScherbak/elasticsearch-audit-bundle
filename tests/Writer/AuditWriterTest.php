<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
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
use Psr\Log\AbstractLogger;

final class AuditWriterTest extends TestCase
{
    private InMemoryGateway $gateway;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testRecordFillsInTimestampActorAndRoutesToTheIndex(): void
    {
        $this->writer(routing: ['auth' => 'audit_auth'])->record('auth', 'alice', 'login_failed', ['ip' => '10.0.0.1']);

        $document = $this->gateway->only('audit_auth');
        unset($document['id']);

        self::assertSame([
            'objectType' => 'auth',
            'objectId' => 'alice',
            'event' => 'login_failed',
            'loggedAt' => '2026-08-26 12:00:00',
            'source' => 'system',
            'changes' => ['ip' => '10.0.0.1'],
        ], $document);
    }

    public function testEveryRecordGetsATimeOrderedIdThatIsAlsoTheDocumentId(): void
    {
        $writer = $this->writer();
        $writer->record('order', 1, AuditEvent::CREATE);
        $writer->record('order', 2, AuditEvent::CREATE);

        [$first, $second] = $this->gateway->documents['audit_log'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['id'], 'a UUID v7');
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame([$first['id'], $second['id']], $this->gateway->ids['audit_log'], 'the same id is the Elasticsearch _id, so a retried write overwrites instead of duplicating');
        $expectedTime = str_pad(dechex((int) (new \DateTimeImmutable('2026-08-26 12:00:00', new \DateTimeZone('UTC')))->format('Uv')), 12, '0', STR_PAD_LEFT);
        self::assertSame($expectedTime, substr(str_replace('-', '', $first['id']), 0, 12), 'the time part comes from loggedAt, not from the wall clock');
    }

    public function testAnExplicitIdIsKept(): void
    {
        $this->writer()->write((new AuditRecord('order', 1, AuditEvent::CREATE))->withId('my-own-id'));

        self::assertSame(['my-own-id'], $this->gateway->ids['audit_log']);
    }

    public function testExplicitActorAndTimeWin(): void
    {
        $this->writer()->record('order', 1, AuditEvent::UPDATE, at: new \DateTimeImmutable('2020-02-02 02:02:02', new \DateTimeZone('UTC')), actor: '7');

        $document = $this->gateway->only('audit_log');

        self::assertSame('7', $document['source']);
        self::assertSame('2020-02-02 02:02:02', $document['loggedAt']);
    }

    public function testEnrichersAddAttributesOnlyToRecordsTheySupport(): void
    {
        $enricher = new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return $record->objectType === 'order';
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes(['salesType' => 3]);
            }

            public function mapping(): array
            {
                return ['salesType' => ['type' => 'integer']];
            }
        };

        $writer = $this->writer(enrichers: [$enricher]);
        $writer->record('order', 1, AuditEvent::CREATE);
        $writer->record('user', 1, AuditEvent::CREATE);

        [$order, $user] = $this->gateway->documents['audit_log'];

        self::assertSame(3, $order['salesType']);
        self::assertArrayNotHasKey('salesType', $user);
    }

    public function testFailuresAreLoggedAndSwallowedByDefault(): void
    {
        $this->gateway->failWith = new \RuntimeException('cluster down');

        $this->writer()->record('order', 1, AuditEvent::UPDATE, ['status' => new Change('a', 'b')]);

        self::assertCount(1, $this->logs);
        self::assertSame('error', $this->logs[0]['level']);
        self::assertStringContainsString('cluster down', (string) $this->logs[0]['context']['reason']);
        self::assertSame('order', $this->logs[0]['context']['objectType']);
    }

    public function testFailuresSurfaceWhenThePolicySaysThrow(): void
    {
        $this->gateway->failWith = new \RuntimeException('cluster down');

        try {
            $this->writer(policy: FailurePolicy::Throw)->record('order', 1, AuditEvent::UPDATE);
            self::fail('Expected WriteFailedException');
        } catch (WriteFailedException $e) {
            self::assertSame('order', $e->record->objectType);
            self::assertStringContainsString('order#1 (update) failed', $e->getMessage());
            // The cluster's own words stay behind getPrevious(): a wrapped message is
            // one the bundle did not write and cannot redact, and this exception is
            // logged in places a value must not reach.
            self::assertStringNotContainsString('cluster down', $e->getMessage());
            self::assertStringContainsString('cluster down', (string) $e->getPrevious()?->getMessage());
            self::assertSame([], $this->logs);
        }
    }

    public function testABrokenEnricherIsAFailureLikeAnyOther(): void
    {
        $enricher = new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                throw new \LogicException('enricher bug');
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $this->writer(enrichers: [$enricher])->record('order', 1, AuditEvent::UPDATE);

        self::assertSame([], $this->gateway->documents);
        self::assertStringContainsString('enricher bug', (string) $this->logs[0]['context']['reason']);
    }

    public function testImmediatelyBypassesTheConfiguredTransport(): void
    {
        $queued = new class implements TransportInterface {
            public int $sent = 0;

            public function send(string $index, array $document, ?string $id = null): void
            {
                ++$this->sent;
            }
        };

        $writer = new AuditWriter($queued, new SyncTransport($this->gateway), new IndexResolver('audit_log'), new ChainActorResolver([]), new FrozenClock());

        $writer->write(new AuditRecord('order', 1, AuditEvent::CREATE));
        $writer->write(new AuditRecord('order', 2, AuditEvent::CREATE), immediately: true);

        self::assertSame(1, $queued->sent);
        self::assertSame(2, $this->gateway->only('audit_log')['objectId']);
    }

    /**
     * @param array<string, string>            $routing
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    private function writer(array $routing = [], iterable $enrichers = [], FailurePolicy $policy = FailurePolicy::Log): AuditWriter
    {
        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
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

        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log', $routing), new ChainActorResolver([], 'system'), new FrozenClock(), $enrichers, $policy, $logger);
    }
}
