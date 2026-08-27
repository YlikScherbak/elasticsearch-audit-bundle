<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\Messenger\FrameResetMiddleware;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

final class AuditFrameTest extends TestCase
{
    private InMemoryGateway $gateway;
    private FrameBuffer $buffer;
    private AuditWriter $writer;
    private AuditFrame $frame;

    /** @var list<object> */
    private array $events = [];

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->buffer = new FrameBuffer();
        $this->writer = $this->writer(FailurePolicy::Log);
        $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());
    }

    public function testOutsideAFrameRecordsGoStraightThrough(): void
    {
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

        self::assertCount(1, $this->gateway->documents['audit_log']);
    }

    public function testInsideAFrameTheOperationIsRecordedOnceWithItsNetEffect(): void
    {
        $this->frame->coalesce(function (): void {
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1000, 1040)]);
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1040, 995)]);
            $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(10, 20)]);
            $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(20, 10)]);

            self::assertSame([], $this->gateway->documents, 'nothing is written while the frame is open');
        });

        $documents = $this->gateway->documents['audit_log'];

        self::assertCount(1, $documents, 'object 2 went back to where it started');
        self::assertSame(1, $documents[0]['objectId']);
        self::assertSame(['fact' => ['old' => 1000, 'new' => 995]], $documents[0]['changes']);
    }

    public function testTheRecordCreatedEventSeesTheCoalescedRecordNotTheSteps(): void
    {
        $this->frame->coalesce(function (): void {
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(2, 3)]);
        });

        $created = array_filter($this->events, static fn (object $e) => $e instanceof RecordCreatedEvent);

        self::assertCount(1, $created);
    }

    public function testCoalesceReturnsTheOperationsResultAndClosesOnException(): void
    {
        self::assertSame(42, $this->frame->coalesce(static fn () => 42));

        try {
            $this->frame->coalesce(function (): void {
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                throw new \RuntimeException('operation failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertFalse($this->frame->isOpen());
        self::assertCount(1, $this->gateway->documents['audit_log'], 'end() in finally still writes what was collected');
    }

    public function testImmediateWritesBypassAnOpenFrame(): void
    {
        $this->frame->begin();
        $this->writer->write(new AuditRecord('stock', 1, AuditEvent::UPDATE, changes: ['fact' => new Change(1, 2)]), immediately: true);

        self::assertCount(1, $this->gateway->documents['audit_log']);

        $this->frame->end();
    }

    public function testResetDropsAnUnclosedFrameAndWarns(): void
    {
        $this->frame->begin();
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

        self::assertTrue($this->frame->reset());
        self::assertFalse($this->frame->reset(), 'nothing left to reset');
        self::assertSame([], $this->gateway->documents, 'a reset frame writes nothing');
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('1 held record(s)', $this->warnings[0]);
    }

    public function testTheMiddlewareReleasesWhatAHandlerLeftOpen(): void
    {
        $middleware = new FrameResetMiddleware($this->frame);
        $handler = new class($this->frame, $this->writer) {
            public function __construct(private readonly AuditFrame $frame, private readonly AuditWriter $writer)
            {
            }

            public function __invoke(object $message): void
            {
                $this->frame->begin(); // no end(): the bug the middleware exists for
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                throw new \RuntimeException('handler died');
            }
        };

        try {
            $middleware->handle(new Envelope(new \stdClass()), new StackMiddleware(new HandlerMiddleware($handler)));
        } catch (\RuntimeException) {
        }

        self::assertFalse($this->frame->isOpen());
        self::assertCount(1, $this->gateway->documents['audit_log'], 'the flush that produced it committed, so the record is real history');
        self::assertCount(1, $this->warnings);

        // The next message is not affected.
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
        self::assertCount(2, $this->gateway->documents['audit_log']);
    }

    public function testEnrichersRunOncePerStepAndNotAgainWhenTheFrameCloses(): void
    {
        $enricher = new class implements \Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface {
            public int $calls = 0;

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes(['pass' => ++$this->calls]);
            }

            public function mapping(): array
            {
                return ['pass' => ['type' => 'integer']];
            }
        };

        $writer = $this->writer(FailurePolicy::Log, [$enricher]);
        $frame = new AuditFrame($this->buffer, $writer, $this->logger());

        $frame->coalesce(static function () use ($writer): void {
            $writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            $writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(2, 3)]);
        });

        self::assertSame(2, $enricher->calls, 'a record is enriched when it enters the frame, not again when it leaves');
        self::assertSame(2, $this->gateway->only('audit_log')['pass'], 'the attributes of the latest step stand');
    }

    public function testAFrameLeftOpenIsReleasedWithAWarning(): void
    {
        $this->frame->begin();
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

        self::assertTrue($this->frame->release());
        self::assertFalse($this->frame->release(), 'nothing left to release');
        self::assertFalse($this->frame->isOpen());
        self::assertCount(1, $this->gateway->documents['audit_log'], 'what the frame held describes changes that did commit');
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('1 held record(s)', $this->warnings[0]);
    }

    public function testWithTheThrowPolicyAFailedWriteSurfacesFromEnd(): void
    {
        $writer = $this->writer(FailurePolicy::Throw);
        $frame = new AuditFrame($this->buffer, $writer);

        $frame->begin();
        $writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
        $this->gateway->failWith = new \RuntimeException('down');

        $this->expectException(WriteFailedException::class);

        $frame->end();
    }

    /**
     * @param list<\Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface> $enrichers
     */
    private function writer(FailurePolicy $policy, array $enrichers = []): AuditWriter
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

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), $enrichers, $policy, null, $dispatcher, $this->buffer);
    }

    private function logger(): AbstractLogger
    {
        $warnings = &$this->warnings;

        return new class($warnings) extends AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->warnings[] = strtr((string) $message, ['{held}' => (string) ($context['held'] ?? '')]);
            }
        };
    }
}

/**
 * The last middleware in the test stack: calls the handler.
 */
final class HandlerMiddleware implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface
{
    public function __construct(private readonly object $handler)
    {
    }

    public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
    {
        ($this->handler)($envelope->getMessage());

        return $envelope;
    }
}
