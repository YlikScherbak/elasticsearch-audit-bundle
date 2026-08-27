<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Redaction is applied where a record leaves the process — after the enrichers,
 * after a frame closed, and on the failure path — so a value that must not be
 * stored also cannot slip out through an event or an exception, and coalescing
 * still sees the real values and keeps the fact that they changed.
 */
final class RedactionInTheWriterTest extends TestCase
{
    private InMemoryGateway $gateway;
    private FrameBuffer $buffer;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->buffer = new FrameBuffer();
    }

    public function testAPasswordChangeInsideAFrameIsStillRecorded(): void
    {
        $writer = $this->writer();
        $frame = new AuditFrame($this->buffer, $writer);

        $frame->coalesce(static function () use ($writer): void {
            $writer->record('user', 1, AuditEvent::UPDATE, ['password' => new Change('hash-a', 'hash-b')]);
        });

        self::assertSame(['password' => ['old' => '***', 'new' => '***']], $this->gateway->only('audit_log')['changes'], 'the frame saw the real values, so it knows the field moved; the index sees the placeholder');
    }

    public function testAPasswordThatCameBackToItsStartIsStillNoise(): void
    {
        $writer = $this->writer();
        $frame = new AuditFrame($this->buffer, $writer);

        $frame->coalesce(static function () use ($writer): void {
            $writer->record('user', 1, AuditEvent::UPDATE, ['password' => new Change('hash-a', 'hash-b')]);
            $writer->record('user', 1, AuditEvent::UPDATE, ['password' => new Change('hash-b', 'hash-a')]);
        });

        self::assertSame([], $this->gateway->documents);
    }

    public function testTheRecordCreatedEventSeesTheRedactedRecord(): void
    {
        $this->writer()->record('user', 1, AuditEvent::UPDATE, ['password' => new Change('hash-a', 'hash-b')]);

        $created = array_values(array_filter($this->events, static fn (object $e) => $e instanceof RecordCreatedEvent));

        self::assertCount(1, $created);
        self::assertSame(['old' => '***', 'new' => '***'], $created[0]->getRecord()->changes['password']->toArray());
    }

    public function testAFailingEnricherDoesNotLeakTheValueThroughTheFailureEvent(): void
    {
        $broken = new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                throw new \RuntimeException('lookup failed');
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $this->writer(enrichers: [$broken])->record('user', 1, AuditEvent::UPDATE, ['password' => new Change('hash-a', 'hash-b')]);

        $failed = array_values(array_filter($this->events, static fn (object $e) => $e instanceof RecordFailedEvent));

        self::assertCount(1, $failed);
        self::assertSame(['old' => '***', 'new' => '***'], $failed[0]->record->changes['password']->toArray(), 'a listener that queues the record for a retry must not receive the secret');
        self::assertSame([], $this->gateway->documents);
    }

    public function testAFailedWriteDoesNotLeakTheValueThroughTheException(): void
    {
        $this->gateway->failWith = new \RuntimeException('down');

        try {
            $this->writer(FailurePolicy::Throw)->record('user', 1, AuditEvent::UPDATE, ['password' => new Change('hash-a', 'hash-b')]);
            self::fail('expected WriteFailedException');
        } catch (WriteFailedException $e) {
            self::assertNotNull($e->record);
            self::assertSame(['old' => '***', 'new' => '***'], $e->record->changes['password']->toArray());
        }
    }

    /**
     * @param list<AuditEnricherInterface> $enrichers
     */
    private function writer(FailurePolicy $policy = FailurePolicy::Log, array $enrichers = []): AuditWriter
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

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), $enrichers, $policy, null, $dispatcher, $this->buffer, new ChangeRedactor(['password']));
    }
}
