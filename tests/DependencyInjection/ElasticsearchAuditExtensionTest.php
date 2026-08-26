<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\Actor\SecurityActorResolver;
use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\MessengerTransport;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ElasticsearchAuditExtensionTest extends TestCase
{
    public function testTheWriterIsWiredWithTheSyncTransportByDefault(): void
    {
        $container = $this->build(['client' => ['hosts' => ['http://localhost:9200']]]);

        self::assertInstanceOf(AuditWriter::class, $container->get(AuditWriter::class));
        self::assertInstanceOf(SyncTransport::class, $container->get(TransportInterface::class));
    }

    public function testTheDoctrineListenerIsAttachedToTheFourLifecycleEvents(): void
    {
        $definition = $this->load(['client' => ['hosts' => ['http://localhost:9200']], 'doctrine' => ['connection' => 'audit']])
            ->getDefinition(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER);

        $events = array_column($definition->getTag('doctrine.event_listener'), 'event');

        self::assertSame(['postPersist', 'postUpdate', 'preRemove', 'postRemove'], $events);
        self::assertSame('audit', $definition->getTag('doctrine.event_listener')[0]['connection']);
    }

    public function testDoctrineAuditingCanBeSwitchedOff(): void
    {
        $container = $this->load(['client' => ['hosts' => ['http://localhost:9200']], 'doctrine' => ['enabled' => false]]);

        self::assertFalse($container->hasDefinition(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER));
    }

    public function testCommandsAreRegisteredForTheConsole(): void
    {
        $container = $this->load(['client' => ['hosts' => ['http://localhost:9200']]]);

        self::assertTrue($container->getDefinition(CreateIndexCommand::class)->hasTag('console.command'));
        self::assertTrue($container->getDefinition(CheckCommand::class)->hasTag('console.command'));
    }

    public function testAnExistingClientServiceIsUsedInsteadOfTheFactory(): void
    {
        $container = $this->build(['client' => ['service' => 'app.es_client']], static function (ContainerBuilder $c): void {
            // Any object will do: the alias is what we are testing, the gateway is replaced below.
            $c->setDefinition('app.es_client', new Definition(\stdClass::class))->setPublic(true);
        });

        self::assertSame($container->get('app.es_client'), $container->get(ElasticsearchAuditExtension::SERVICE_CLIENT));
    }

    public function testMessengerTransportRegistersTheHandler(): void
    {
        $container = $this->build(
            ['client' => ['hosts' => ['http://localhost:9200']], 'transport' => 'messenger', 'message_bus' => 'app.bus'],
            static function (ContainerBuilder $c): void {
                $c->setDefinition('app.bus', new Definition(RecordingBus::class))->setPublic(true);
            },
        );

        self::assertInstanceOf(MessengerTransport::class, $container->get(TransportInterface::class));

        $definitions = $this->load(['client' => ['hosts' => ['http://localhost:9200']], 'transport' => 'messenger']);

        self::assertTrue($definitions->getDefinition(IndexAuditRecordHandler::class)->hasTag('messenger.message_handler'));
    }

    public function testEnrichersAreCollectedByAutoconfiguration(): void
    {
        $container = $this->build(['client' => ['hosts' => ['http://localhost:9200']]], static function (ContainerBuilder $c): void {
            $c->setDefinition(TenantEnricher::class, (new Definition(TenantEnricher::class))->setAutoconfigured(true));
        });

        /** @var AuditWriter $writer */
        $writer = $container->get(AuditWriter::class);
        $writer->record('order', 1, 'create');

        /** @var InMemoryGateway $gateway */
        $gateway = $container->get(GatewayInterface::class);

        self::assertSame('acme', $gateway->only('audit_log')['tenant']);
    }

    public function testSecurityResolverIsRegisteredWhenSecurityCoreIsInstalled(): void
    {
        $container = $this->build(['client' => ['hosts' => ['http://localhost:9200']]]);

        self::assertTrue($container->hasDefinition(SecurityActorResolver::class));
        self::assertTrue($container->getDefinition(SecurityActorResolver::class)->hasTag(ElasticsearchAuditExtension::TAG_ACTOR_RESOLVER));
    }

    /**
     * The container right after the extension ran — for asserting on definitions and
     * tags that a compile would remove as unused (commands, message handlers).
     *
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new ElasticsearchAuditExtension())->load([$config], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function build(array $config, ?callable $configure = null): ContainerBuilder
    {
        $container = $this->load($config);

        if ($configure !== null) {
            $configure($container);
        }

        // Nothing here should ever talk to a real cluster.
        $container->setDefinition(ElasticsearchAuditExtension::SERVICE_GATEWAY, new Definition(InMemoryGateway::class))->setPublic(true);

        foreach ([TransportInterface::class, GatewayInterface::class, ElasticsearchAuditExtension::SERVICE_CLIENT] as $id) {
            $container->hasAlias($id) ? $container->getAlias($id)->setPublic(true) : $container->getDefinition($id)->setPublic(true);
        }

        $container->compile();

        return $container;
    }
}

final class TenantEnricher implements AuditEnricherInterface
{
    public function supports(AuditRecord $record): bool
    {
        return true;
    }

    public function enrich(AuditRecord $record): AuditRecord
    {
        return $record->withAttributes(['tenant' => 'acme']);
    }

    public function mapping(): array
    {
        return ['tenant' => ['type' => 'keyword']];
    }
}

final class RecordingBus implements MessageBusInterface
{
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return new Envelope($message);
    }
}
