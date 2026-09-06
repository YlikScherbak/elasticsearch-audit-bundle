<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Examples;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\Configuration;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Examples\Extending\ActingOnBehalfOfResolver;
use Borsche\ElasticsearchAuditBundle\Examples\Extending\NetEffectEnricher;
use Borsche\ElasticsearchAuditBundle\Examples\Extending\ReactingToRecords;
use Borsche\ElasticsearchAuditBundle\Examples\Extending\SalesChannelEnricher;
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
use Psr\Log\AbstractLogger;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Yaml\Yaml;

/**
 * The extending and operating examples, run — including the configuration file,
 * which is put through the bundle's own configuration tree. A renamed setting
 * fails here rather than in somebody's application.
 */
final class ExtendingExamplesTest extends TestCase
{
    private InMemoryGateway $gateway;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testTheAnnotatedConfigurationIsOneTheBundleAccepts(): void
    {
        /** @var array{borsche_elasticsearch_audit: array<string, mixed>} $parsed */
        $parsed = Yaml::parseFile(__DIR__.'/../../examples/Operating/configuration.yaml');

        $config = (new Processor())->processConfiguration(
            new Configuration(),
            [$parsed['borsche_elasticsearch_audit']],
        );

        // Spot-check the values the file explains at length, so a default that moves
        // is caught by the example that documents it.
        self::assertSame('audit_log', $config['indices']['default']);
        self::assertSame('audit_auth_log', $config['indices']['routing']['auth']);
        self::assertSame('audit_stock_log', $config['indices']['routing']['warehouse-stock'], 'a dash in an object type survives the config tree');
        self::assertSame('log', $config['on_failure']);
        self::assertSame(16, $config['redact']['max_depth']);
        self::assertSame('cause', $config['redact']['failure_details']);
        self::assertSame('release', $config['coalescing']['on_overflow']);
        self::assertSame('auto', $config['doctrine']['enabled']);
    }

    public function testTheEnricherAddsItsAttributeAndDeclaresItsMapping(): void
    {
        $enricher = new SalesChannelEnricher([7 => 'marketplace']);
        $writer = $this->writer([$enricher]);

        $writer->record('order', 7, AuditEvent::UPDATE, ['status' => new Change('draft', 'approved')]);
        $writer->record('ticket', 7, AuditEvent::UPDATE, ['state' => new Change('open', 'closed')]);

        $documents = $this->gateway->documents['audit_log'];

        self::assertSame('marketplace', $documents[0]['salesChannel'], 'an attribute is a top-level field of the document');
        self::assertArrayNotHasKey('salesChannel', $documents[1], 'and supports() decides who gets one');

        // What audit:index:create would put in the mapping, and what audit:check
        // compares the live index against.
        $definition = (new IndexDefinition(properties: $enricher->mapping()))->toArray();

        self::assertSame(['type' => 'keyword'], $definition['mappings']['properties']['salesChannel']);
    }

    public function testTheMergedEnricherSeesTheOperationAndNotTheStep(): void
    {
        $buffer = new FrameBuffer();
        $writer = $this->writer([new NetEffectEnricher()], $buffer);
        $frame = new AuditFrame($buffer, $writer);

        $frame->coalesce(static function () use ($writer): void {
            $writer->record('order', 1, AuditEvent::UPDATE, ['totalCents' => new Change(1000, 4000)]);
            $writer->record('order', 1, AuditEvent::UPDATE, ['totalCents' => new Change(4000, 1500)]);
        });

        $document = $this->gateway->documents['audit_log'][0];

        // 1000 → 1500 over the whole operation. An ordinary enricher would have run
        // on each step and left the last step's answer (-2500) on a record whose own
        // changes say +500.
        self::assertSame(['old' => 1000, 'new' => 1500], $document['changes']['totalCents']);
        self::assertSame(500, $document['totalDeltaCents']);
    }

    public function testTheResolverAnswersForWorkThatHasNoSecurityToken(): void
    {
        $resolver = new ActingOnBehalfOfResolver();
        $chain = new ChainActorResolver([$resolver], 'system');

        // Nothing set: this resolver does not know, and the chain falls through to
        // the configured fallback rather than to a made-up name.
        self::assertSame('system', $chain->resolve());

        $resolver->actingAs('u-42');
        self::assertSame('u-42', $chain->resolve());

        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), $chain, new FrozenClock());
        $writer->record('order', 1, AuditEvent::UPDATE, ['status' => new Change('draft', 'approved')]);

        self::assertSame('u-42', $this->gateway->documents['audit_log'][0]['source']);
    }

    public function testTheListenerDropsWhatNobodyWantsAndAddsWhatTheyDo(): void
    {
        $dispatcher = new EventDispatcher();
        $listener = new ReactingToRecords($this->logger());

        $dispatcher->addListener(RecordCreatedEvent::class, $listener->dropWhatNobodyWantsToRead(...));
        $dispatcher->addListener(RecordCreatedEvent::class, $listener->addTheRequestItCameFrom(...));

        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Log, null, $dispatcher);

        $writer->record('heartbeat', 1, AuditEvent::UPDATE, ['beat' => new Change(1, 2)]);
        $writer->record('order', 1, AuditEvent::UPDATE, ['lastSeenAt' => new Change('a', 'b')]);
        $writer->record('order', 2, AuditEvent::UPDATE, ['status' => new Change('draft', 'approved')]);

        $documents = $this->gateway->documents['audit_log'];

        self::assertCount(1, $documents, 'the heartbeat and the noise update were vetoed');
        self::assertSame(2, $documents[0]['objectId']);
        self::assertSame('web', $documents[0]['channel'], 'and the replacement reached the index');
    }

    public function testTheFailureListenerSeesWhatWasNotWritten(): void
    {
        $dispatcher = new EventDispatcher();
        $listener = new ReactingToRecords($this->logger());
        $dispatcher->addListener(RecordFailedEvent::class, $listener->countAndAlert(...));

        $this->gateway->failWith = new \RuntimeException('the cluster is down');

        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Log, null, $dispatcher);

        $writer->write(new AuditRecord('order', 9, AuditEvent::UPDATE, changes: ['status' => new Change('draft', 'approved')]));

        self::assertSame([], $this->gateway->documents, 'nothing was written');
        self::assertNotEmpty(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'order #9')), 'and something was watching');
    }

    /**
     * @param list<AuditEnricherInterface> $enrichers
     */
    private function writer(array $enrichers = [], ?FrameBuffer $buffer = null): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), $enrichers, FailurePolicy::Throw, null, null, $buffer);
    }

    private function logger(): AbstractLogger
    {
        $logs = &$this->logs;

        return new class($logs) extends AbstractLogger {
            /** @param list<string> $logs */
            public function __construct(private array &$logs)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->logs[] = strtr((string) $message, [
                    '{type}' => (string) ($context['type'] ?? ''),
                    '{id}' => (string) ($context['id'] ?? ''),
                    '{event}' => (string) ($context['event'] ?? ''),
                ]);
            }
        };
    }
}
