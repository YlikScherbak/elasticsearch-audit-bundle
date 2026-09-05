<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailureDetails;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class AuditWriterEventsTest extends TestCase
{
    private InMemoryGateway $gateway;

    /** @var list<object> */
    private array $dispatched = [];

    /** @var (callable(object): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testRecordCreatedIsDispatchedWithTheCompletedRecord(): void
    {
        $this->writer()->record('order', 1, AuditEvent::CREATE);

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(RecordCreatedEvent::class, $this->dispatched[0]);
        self::assertSame('system', $this->dispatched[0]->getRecord()->actor, 'listeners see the record after completion');
    }

    public function testAListenerCanReplaceTheRecord(): void
    {
        $this->listener = static function (object $event): void {
            if ($event instanceof RecordCreatedEvent) {
                $event->setRecord($event->getRecord()->withAttributes(['redacted' => true]));
            }
        };

        $this->writer()->record('order', 1, AuditEvent::CREATE);

        self::assertTrue($this->gateway->only('audit_log')['redacted']);
    }

    public function testAVetoedRecordIsNeitherWrittenNorReportedAsAFailure(): void
    {
        $this->listener = static function (object $event): void {
            if ($event instanceof RecordCreatedEvent) {
                $event->veto();
            }
        };

        $this->writer(FailurePolicy::Throw)->record('order', 1, AuditEvent::CREATE);

        self::assertSame([], $this->gateway->documents);
        self::assertCount(1, $this->dispatched);
    }

    public function testRecordFailedIsDispatchedWhateverThePolicy(): void
    {
        $this->gateway->failWith = new \RuntimeException('down');

        $this->writer(FailurePolicy::Log)->record('order', 1, AuditEvent::CREATE);

        $failed = array_values(array_filter($this->dispatched, static fn (object $e) => $e instanceof RecordFailedEvent));

        self::assertCount(1, $failed);
        self::assertSame('order', $failed[0]->record->objectType);
        self::assertStringContainsString('down', self::chainOf($failed[0]->reason));
    }

    private function writer(FailurePolicy $policy = FailurePolicy::Log): AuditWriter
    {
        $dispatcher = new class($this->dispatched, $this->listener) implements EventDispatcherInterface {
            /**
             * @param list<object>                  $dispatched
             * @param (callable(object): void)|null $listener
             */
            public function __construct(private array &$dispatched, private $listener)
            {
            }

            public function dispatch(object $event): object
            {
                $this->dispatched[] = $event;

                if ($this->listener !== null) {
                    ($this->listener)($event);
                }

                return $event;
            }
        };

        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], $policy, null, $dispatcher, failureDetails: FailureDetails::Full);
    }

    /**
     * Every message in an exception chain, joined. The bundle names a foreign cause
     * rather than quoting it, so a diagnostic that used to sit one getPrevious() away
     * may now sit two or three; what matters is that it is still reachable.
     */
    private static function chainOf(?\Throwable $e): string
    {
        $said = [];

        for (; $e !== null; $e = $e->getPrevious()) {
            $said[] = $e->getMessage();
        }

        return implode(' | ', $said);
    }
}
