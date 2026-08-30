<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
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
 * A redacted value must not exist anywhere an application can see it — not in the
 * document, not in an event, not in an exception, not in a log line. Asserting field
 * by field misses whatever is added next; this looks at everything that came out.
 */
final class NothingLeavesUnredactedTest extends TestCase
{
    private const SECRET = 'hunter2-the-actual-password';

    public function testAnAttributeIsNotAWayPastRedaction(): void
    {
        // The channel redaction did not cover is the indexed one: `changes` is stored
        // and not searchable, an attribute is searchable.
        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['password']);

        $writer->record('user', 7, 'update', ['name' => new Change('a', 'b')], ['password' => self::SECRET, 'tenant' => 'acme']);

        $document = $gateway->documents['audit_log'][0];

        self::assertArrayNotHasKey('password', $document, 'a redacted attribute is not written at all');
        self::assertSame('acme', $document['tenant']);
    }

    public function testATypedAttributeIsNotMaskedIntoSomethingTheMappingRefuses(): void
    {
        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['salesType']);

        $writer->record('order', 1, 'update', ['status' => new Change('a', 'b')], ['salesType' => 2]);

        self::assertArrayNotHasKey('salesType', $gateway->documents['audit_log'][0], "an integer field masked with '***' would make Elasticsearch refuse the document");
    }

    public function testAListenerCannotHandBackWhatWasRemoved(): void
    {
        $gateway = new InMemoryGateway();
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof RecordCreatedEvent) {
                    // A listener that means well and reaches for the entity again.
                    $event->setRecord($event->getRecord()->withChanges(['password' => new Change(null, NothingLeavesUnredactedTest::secret())]));
                }

                return $event;
            }
        };

        $writer = $this->writer($gateway, ['password'], $events);
        $writer->record('user', 7, 'update', ['password' => new Change(null, self::SECRET)]);

        self::assertStringNotContainsString(self::SECRET, json_encode($gateway->documents, JSON_THROW_ON_ERROR));
    }

    public function testTheSecretIsInNothingThatCameOut(): void
    {
        $gateway = new InMemoryGateway();
        $gateway->failWith = new \RuntimeException('the cluster said no');

        $seen = [];
        $events = new class($seen) implements EventDispatcherInterface {
            /** @param list<object> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof RecordCreatedEvent || $event instanceof RecordFailedEvent) {
                    $this->seen[] = $event;
                }

                return $event;
            }
        };

        $logs = [];
        $writer = $this->writer($gateway, ['password'], $events, $logs, FailurePolicy::Log);

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::SECRET)], ['password' => self::SECRET]);

        $observable = json_encode([
            'documents' => $gateway->documents,
            'events' => array_map(static fn (object $e): array => (array) $e, $seen),
            'logs' => $logs,
        ], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);

        self::assertStringNotContainsString(self::SECRET, $observable);
    }

    public static function secret(): string
    {
        return self::SECRET;
    }

    /**
     * @param list<string>       $redact
     * @param list<string>       $logs
     */
    private function writer(InMemoryGateway $gateway, array $redact, ?EventDispatcherInterface $events = null, array &$logs = [], FailurePolicy $policy = FailurePolicy::Throw): AuditWriter
    {
        $transport = new SyncTransport($gateway);
        $logger = new class($logs) extends \Psr\Log\AbstractLogger {
            /** @param list<string> $logs */
            public function __construct(private array &$logs)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->logs[] = (string) $message.' '.json_encode(array_map(static fn (mixed $v): mixed => \is_object($v) ? (string) $v : $v, $context), JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        };

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), [], $policy, $logger, $events, null, new ChangeRedactor($redact, '***'));
    }
}
