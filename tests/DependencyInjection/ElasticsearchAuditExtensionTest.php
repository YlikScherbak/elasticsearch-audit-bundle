<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\Actor\SecurityActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
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

    public function testRedactedFieldsNeverReachTheTransport(): void
    {
        $container = $this->build(['client' => ['hosts' => ['http://localhost:9200']], 'redact' => ['fields' => ['password'], 'placeholder' => 'xxx']]);

        /** @var AuditWriter $writer */
        $writer = $container->get(AuditWriter::class);
        $writer->record('user', 1, 'update', ['password' => new Change('hunter2', 'letmein'), 'name' => new Change('a', 'b')]);

        /** @var InMemoryGateway $gateway */
        $gateway = $container->get(GatewayInterface::class);
        $changes = $gateway->only('audit_log')['changes'];

        self::assertSame(['old' => 'xxx', 'new' => 'xxx'], $changes['password']);
        self::assertSame(['old' => 'a', 'new' => 'b'], $changes['name']);
    }

    public function testWithoutConfiguredFieldsThereIsNoRedactor(): void
    {
        $container = $this->load(['client' => ['hosts' => ['http://localhost:9200']]]);

        self::assertFalse($container->hasDefinition(ChangeRedactor::class));
    }

    public function testTheFrameIsWiredIntoTheWriter(): void
    {
        $container = $this->build(['client' => ['hosts' => ['http://localhost:9200']], 'coalescing' => ['numeric_fields' => ['fact']]]);

        /** @var AuditWriter $writer */
        $writer = $container->get(AuditWriter::class);
        /** @var AuditFrame $frame */
        $frame = $container->get(AuditFrame::class);
        /** @var InMemoryGateway $gateway */
        $gateway = $container->get(GatewayInterface::class);

        $frame->coalesce(static function () use ($writer): void {
            $writer->record('stock', 1, 'update', ['fact' => new Change(null, 0), 'name' => new Change('a', 'b')]);
            $writer->record('stock', 1, 'update', ['name' => new Change('b', 'c')]);
        });

        self::assertSame(['name' => ['old' => 'a', 'new' => 'c']], $gateway->only('audit_log')['changes'], 'null → 0 on a numeric field is not a change');
    }

    public function testCoalescingCanBeSwitchedOffWithoutBreakingTheCodeThatOpensFrames(): void
    {
        $container = $this->build(['client' => ['hosts' => ['http://localhost:9200']], 'coalescing' => ['enabled' => false]]);

        /** @var AuditWriter $writer */
        $writer = $container->get(AuditWriter::class);
        /** @var AuditFrame $frame */
        $frame = $container->get(AuditFrame::class);
        /** @var InMemoryGateway $gateway */
        $gateway = $container->get(GatewayInterface::class);

        $frame->coalesce(static function () use ($writer): void {
            $writer->record('stock', 1, 'update', ['fact' => new Change(1, 2)]);
            $writer->record('stock', 1, 'update', ['fact' => new Change(2, 3)]);
        });

        self::assertCount(2, $gateway->documents['audit_log'], 'with coalescing off a frame holds nothing and every step is its own record');
    }

    public function testNumericFieldsCanBeScopedToAnObjectType(): void
    {
        $container = $this->build(['client' => ['hosts' => ['http://localhost:9200']], 'coalescing' => ['numeric_fields' => ['stock.fact']]]);

        /** @var AuditWriter $writer */
        $writer = $container->get(AuditWriter::class);
        /** @var AuditFrame $frame */
        $frame = $container->get(AuditFrame::class);
        /** @var InMemoryGateway $gateway */
        $gateway = $container->get(GatewayInterface::class);

        $frame->coalesce(static function () use ($writer): void {
            $writer->record('stock', 1, 'update', ['fact' => new Change(null, 0)]);
            $writer->record('order', 1, 'update', ['fact' => new Change(null, 0)]);
        });

        self::assertCount(1, $gateway->documents['audit_log'], 'the scoped field is numeric for stock only');
        self::assertSame('order', $gateway->only('audit_log')['objectType']);
    }

    public function testTheReaderIsWiredWithExtensionsAndDecorators(): void
    {
        $container = $this->build(['client' => ['hosts' => ['http://localhost:9200']]], static function (ContainerBuilder $c): void {
            $c->setDefinition(MineOnlyExtension::class, (new Definition(MineOnlyExtension::class))->setAutoconfigured(true));
            $c->setDefinition(ActorNameDecorator::class, (new Definition(ActorNameDecorator::class))->setAutoconfigured(true));
        });

        /** @var InMemoryGateway $gateway */
        $gateway = $container->get(GatewayInterface::class);
        $gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 1], 'hits' => [
            ['_id' => 'x', '_source' => ['objectType' => 'order', 'objectId' => 1, 'event' => 'update', 'loggedAt' => '2026-08-26 10:00:00', 'source' => 'me', 'changes' => []]],
        ]]];

        /** @var AuditReader $reader */
        $reader = $container->get(AuditReader::class);
        $page = $reader->find(AuditQuery::for('order'));

        self::assertContains(['term' => ['source' => 'me']], $gateway->searches[0]['body']['query']['bool']['filter']);
        self::assertSame('Me, Myself', $page->entries[0]->extra['actorName']);
    }

    public function testTheDoctrineListenerIsAttachedToEveryEventItNeeds(): void
    {
        $definition = $this->load(['client' => ['hosts' => ['http://localhost:9200']], 'doctrine' => ['connection' => 'audit']])
            ->getDefinition(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER);

        $events = array_column($definition->getTag('doctrine.event_listener'), 'event');

        self::assertSame(['onFlush', 'postPersist', 'postUpdate', 'preRemove', 'postRemove', 'postFlush', 'onClear'], $events);
        self::assertSame('audit', $definition->getTag('doctrine.event_listener')[0]['connection']);
    }

    public function testDoctrineAuditingCanBeSwitchedOff(): void
    {
        $container = $this->load(['client' => ['hosts' => ['http://localhost:9200']], 'doctrine' => ['enabled' => false]]);

        self::assertFalse($container->hasDefinition(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER));
    }

    public function testDoctrineExplicitlyOnWithoutTheOrmRefusesToBoot(): void
    {
        // Someone wrote doctrine: { enabled: true } and does not have doctrine/orm:
        // they asked for entity auditing and are not getting it, and a silent skip is
        // how that surfaces months later, as the history that was never written. It
        // fails the way the messenger transport without symfony/messenger does — at
        // boot, by name.
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('doctrine/orm');

        (new ElasticsearchAuditExtension(ormInstalled: false))
            ->load([['client' => ['hosts' => ['http://localhost:9200']], 'doctrine' => ['enabled' => true]]], new ContainerBuilder());
    }

    public function testWithoutTheOrmDoctrineAuditingQuietlyStaysOff(): void
    {
        // Nobody asked for it: the default means "when doctrine/orm is there", and it is not.
        $container = new ContainerBuilder();
        (new ElasticsearchAuditExtension(ormInstalled: false))->load([['client' => ['hosts' => ['http://localhost:9200']]]], $container);

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

    public function testTheComparatorChainIsNotTaggedIntoItsOwnIterator(): void
    {
        // It implements the interface the autoconfiguration tags, and a chain that is
        // asked to include itself recurses until the stack gives out. It is defined
        // explicitly and never autoconfigured, which is what keeps it out.
        $chain = $this->load(['client' => ['hosts' => ['http://localhost:9200']]])
            ->getDefinition(ElasticsearchAuditExtension::SERVICE_VALUE_COMPARATOR);

        self::assertFalse($chain->isAutoconfigured());
        self::assertSame([], $chain->getTag(ElasticsearchAuditExtension::TAG_VALUE_COMPARATOR));
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

final class MineOnlyExtension implements QueryExtensionInterface
{
    public function extend(AuditQuery $query): AuditQuery
    {
        return $query->withActors('me');
    }
}

final class ActorNameDecorator implements RecordDecoratorInterface
{
    public function decorate(array $entries): array
    {
        return array_map(static fn (AuditEntry $e) => $e->withExtra(['actorName' => 'Me, Myself']), $entries);
    }

}
