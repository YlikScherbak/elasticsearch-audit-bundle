<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Actor\SecurityActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\Messenger\FrameResetMiddleware;
use Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ClientFactory;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Reader\QueryBuilder;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\MessengerTransport;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Borsche\ElasticsearchAuditBundle\Writer\SystemClock;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Client;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class ElasticsearchAuditExtension extends Extension
{
    public const TAG_ENRICHER = 'borsche_elasticsearch_audit.enricher';
    public const TAG_ACTOR_RESOLVER = 'borsche_elasticsearch_audit.actor_resolver';
    public const TAG_QUERY_EXTENSION = 'borsche_elasticsearch_audit.query_extension';
    public const TAG_DECORATOR = 'borsche_elasticsearch_audit.decorator';
    public const SERVICE_READER = 'borsche_elasticsearch_audit.reader';
    public const TAG_VALUE_COMPARATOR = 'borsche_elasticsearch_audit.value_comparator';
    public const SERVICE_FRAME_BUFFER = 'borsche_elasticsearch_audit.coalescing.buffer';
    public const SERVICE_FRAME = 'borsche_elasticsearch_audit.coalescing.frame';

    public const SERVICE_CLIENT = 'borsche_elasticsearch_audit.client';
    public const SERVICE_GATEWAY = 'borsche_elasticsearch_audit.gateway';
    public const SERVICE_TRANSPORT = 'borsche_elasticsearch_audit.transport';
    public const SERVICE_SYNC_TRANSPORT = 'borsche_elasticsearch_audit.transport.sync';
    public const SERVICE_INDEX_RESOLVER = 'borsche_elasticsearch_audit.index_resolver';
    public const SERVICE_INDEX_DEFINITION = 'borsche_elasticsearch_audit.index_definition';
    public const SERVICE_ACTOR_RESOLVER = 'borsche_elasticsearch_audit.actor_resolver';
    public const SERVICE_CLOCK = 'borsche_elasticsearch_audit.clock';
    public const SERVICE_WRITER = 'borsche_elasticsearch_audit.writer';
    public const SERVICE_METADATA_FACTORY = 'borsche_elasticsearch_audit.doctrine.metadata_factory';
    public const SERVICE_DOCTRINE_LISTENER = 'borsche_elasticsearch_audit.doctrine.listener';

    public function getAlias(): string
    {
        return Configuration::ROOT;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->registerForAutoconfiguration(AuditEnricherInterface::class)->addTag(self::TAG_ENRICHER);
        $container->registerForAutoconfiguration(ActorResolverInterface::class)->addTag(self::TAG_ACTOR_RESOLVER);
        $container->registerForAutoconfiguration(QueryExtensionInterface::class)->addTag(self::TAG_QUERY_EXTENSION);
        $container->registerForAutoconfiguration(RecordDecoratorInterface::class)->addTag(self::TAG_DECORATOR);

        $this->registerClient($config['client'], $container);
        $this->registerIndices($config['indices'], $container);
        $this->registerTransport($config['transport'], $config['message_bus'], $container);
        $this->registerActor($config['actor'], $container);
        $this->registerRedaction($config['redact'], $container);
        $this->registerCoalescing($config['coalescing'], $container);
        $this->registerWriter($config['on_failure'], $container);
        $this->registerReader($config['reader'], $container);
        $this->registerDoctrine($config['doctrine'], $container);
        $this->registerCommands($container);
    }

    /**
     * @param array{fields: list<string>, placeholder: string} $redact
     */
    private function registerRedaction(array $redact, ContainerBuilder $container): void
    {
        if ($redact['fields'] === []) {
            return;
        }

        // Not an enricher: the writer applies it on the way out, after the enrichers and after
        // a frame closed, so redaction cannot hide from coalescing that a field moved.
        $container->setDefinition(ChangeRedactor::class, new Definition(ChangeRedactor::class, [$redact['fields'], $redact['placeholder']]));
    }

    /**
     * @param array{enabled: bool, object_types: list<string>, numeric_fields: list<string>, max_held: int} $coalescing
     */
    private function registerCoalescing(array $coalescing, ContainerBuilder $container): void
    {
        // Registered even when coalescing is off: code that opens frames keeps working,
        // the buffer simply holds nothing, so switching the feature off is a config change
        // and not a refactoring.
        $container->registerForAutoconfiguration(ValueComparatorInterface::class)->addTag(self::TAG_VALUE_COMPARATOR);

        if ($coalescing['numeric_fields'] !== []) {
            $container->setDefinition(NumericNullAsZeroComparator::class, (new Definition(NumericNullAsZeroComparator::class, [$coalescing['numeric_fields']]))
                ->addTag(self::TAG_VALUE_COMPARATOR, ['priority' => -100]));
        }

        $container->setDefinition(self::SERVICE_FRAME_BUFFER, new Definition(FrameBuffer::class, [
            new Definition(ValueComparator::class, [new TaggedIteratorArgument(self::TAG_VALUE_COMPARATOR)]),
            $coalescing['object_types'],
            $coalescing['max_held'],
            $coalescing['enabled'],
        ]));

        $container->setDefinition(self::SERVICE_FRAME, new Definition(AuditFrame::class, [
            new Reference(self::SERVICE_FRAME_BUFFER),
            new Reference(self::SERVICE_WRITER),
            new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setAlias(AuditFrame::class, self::SERVICE_FRAME)->setPublic(true);

        if (interface_exists(MessageBusInterface::class)) {
            $container->setDefinition(FrameResetMiddleware::class, new Definition(FrameResetMiddleware::class, [new Reference(self::SERVICE_FRAME)]));
        }
    }

    /**
     * @param array{point_in_time_keep_alive: string, max_limit: int, max_result_window: int} $reader
     */
    private function registerReader(array $reader, ContainerBuilder $container): void
    {
        $container->setDefinition(self::SERVICE_READER, new Definition(AuditReader::class, [
            new Reference(self::SERVICE_GATEWAY),
            new Reference(self::SERVICE_INDEX_RESOLVER),
            new Definition(QueryBuilder::class),
            new TaggedIteratorArgument(self::TAG_QUERY_EXTENSION),
            new TaggedIteratorArgument(self::TAG_DECORATOR),
            $reader['point_in_time_keep_alive'],
            $reader['max_limit'],
            $reader['max_result_window'],
        ]));
        $container->setAlias(AuditReader::class, self::SERVICE_READER)->setPublic(true);
    }

    /**
     * @param array{enabled: bool, skip_empty_updates: bool, connection: string} $doctrine
     */
    private function registerDoctrine(array $doctrine, ContainerBuilder $container): void
    {
        if (!$doctrine['enabled'] || !interface_exists(EntityManagerInterface::class)) {
            return;
        }

        $container->setDefinition(self::SERVICE_METADATA_FACTORY, new Definition(AuditMetadataFactory::class));
        $container->setAlias(AuditMetadataFactory::class, self::SERVICE_METADATA_FACTORY);

        $listener = new Definition(AuditSubscriber::class, [
            new Reference(self::SERVICE_WRITER),
            new Reference(self::SERVICE_METADATA_FACTORY),
            $doctrine['skip_empty_updates'],
        ]);

        foreach (AuditSubscriber::EVENTS as $event) {
            $listener->addTag('doctrine.event_listener', ['event' => $event, 'connection' => $doctrine['connection']]);
        }

        $container->setDefinition(self::SERVICE_DOCTRINE_LISTENER, $listener);
    }

    /**
     * @param array{hosts: list<string>, service: ?string, ssl_verification: bool} $client
     */
    private function registerClient(array $client, ContainerBuilder $container): void
    {
        if ($client['service'] !== null) {
            $container->setAlias(self::SERVICE_CLIENT, $client['service']);
        } else {
            $container->setDefinition(self::SERVICE_CLIENT, (new Definition(Client::class))
                ->setFactory([ClientFactory::class, 'create'])
                ->setArguments([
                    $client['hosts'],
                    $client['ssl_verification'],
                    new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ]));
        }

        $container->setDefinition(self::SERVICE_GATEWAY, new Definition(ElasticsearchGateway::class, [new Reference(self::SERVICE_CLIENT)]));
        $container->setAlias(GatewayInterface::class, self::SERVICE_GATEWAY);
    }

    /**
     * @param array{default: string, routing: array<string, string>, object_id_type: string, settings: array<string, mixed>} $indices
     */
    private function registerIndices(array $indices, ContainerBuilder $container): void
    {
        $container->setDefinition(self::SERVICE_INDEX_RESOLVER, new Definition(IndexResolver::class, [$indices['default'], $indices['routing']]));
        $container->setAlias(IndexResolver::class, self::SERVICE_INDEX_RESOLVER);

        $container->setDefinition(self::SERVICE_INDEX_DEFINITION, new Definition(IndexDefinition::class, [
            $indices['object_id_type'],
            [], // enricher mappings are merged at runtime by the commands
            $indices['settings'],
        ]));
        $container->setAlias(IndexDefinition::class, self::SERVICE_INDEX_DEFINITION);
    }

    private function registerTransport(string $transport, string $busId, ContainerBuilder $container): void
    {
        $container->setDefinition(self::SERVICE_SYNC_TRANSPORT, new Definition(SyncTransport::class, [new Reference(self::SERVICE_GATEWAY)]));

        if ($transport === 'messenger') {
            if (!interface_exists(MessageBusInterface::class)) {
                throw new NotConfiguredException('transport "messenger" needs symfony/messenger: composer require symfony/messenger, or set transport to "sync".');
            }

            $container->setDefinition(self::SERVICE_TRANSPORT, new Definition(MessengerTransport::class, [new Reference($busId)]));
            $container->setDefinition(IndexAuditRecordHandler::class, (new Definition(IndexAuditRecordHandler::class, [new Reference(self::SERVICE_GATEWAY)]))
                ->addTag('messenger.message_handler'));
            $container->setDefinition(IndexAuditRecordsHandler::class, (new Definition(IndexAuditRecordsHandler::class, [new Reference(self::SERVICE_GATEWAY)]))
                ->addTag('messenger.message_handler'));
        } else {
            $container->setAlias(self::SERVICE_TRANSPORT, self::SERVICE_SYNC_TRANSPORT);
        }

        $container->setAlias(TransportInterface::class, self::SERVICE_TRANSPORT);
    }

    /**
     * @param array{fallback: string} $actor
     */
    private function registerActor(array $actor, ContainerBuilder $container): void
    {
        if (interface_exists(TokenStorageInterface::class)) {
            $container->setDefinition(SecurityActorResolver::class, (new Definition(SecurityActorResolver::class, [
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]))->addTag(self::TAG_ACTOR_RESOLVER, ['priority' => -100]));
        }

        $container->setDefinition(self::SERVICE_ACTOR_RESOLVER, new Definition(ChainActorResolver::class, [
            new TaggedIteratorArgument(self::TAG_ACTOR_RESOLVER),
            $actor['fallback'],
        ]));
        $container->setAlias(ActorResolverInterface::class, self::SERVICE_ACTOR_RESOLVER);
    }

    private function registerWriter(string $onFailure, ContainerBuilder $container): void
    {
        // Override the alias (e.g. with symfony/clock's service) to control time in tests.
        $container->setDefinition(self::SERVICE_CLOCK, new Definition(SystemClock::class));

        $container->setDefinition(self::SERVICE_WRITER, new Definition(AuditWriter::class, [
            new Reference(self::SERVICE_TRANSPORT),
            new Reference(self::SERVICE_SYNC_TRANSPORT),
            new Reference(self::SERVICE_INDEX_RESOLVER),
            new Reference(self::SERVICE_ACTOR_RESOLVER),
            new Reference(self::SERVICE_CLOCK),
            new TaggedIteratorArgument(self::TAG_ENRICHER),
            FailurePolicy::from($onFailure),
            new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(EventDispatcherInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(self::SERVICE_FRAME_BUFFER, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(ChangeRedactor::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setAlias(AuditWriter::class, self::SERVICE_WRITER)->setPublic(true);
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        if (!class_exists(Command::class)) {
            return;
        }

        $container->setDefinition(CreateIndexCommand::class, (new Definition(CreateIndexCommand::class, [
            new Reference(self::SERVICE_GATEWAY),
            new Reference(self::SERVICE_INDEX_RESOLVER),
            new Reference(self::SERVICE_INDEX_DEFINITION),
            new TaggedIteratorArgument(self::TAG_ENRICHER),
        ]))->addTag('console.command'));

        $container->setDefinition(CheckCommand::class, (new Definition(CheckCommand::class, [
            new Reference(self::SERVICE_GATEWAY),
            new Reference(self::SERVICE_INDEX_RESOLVER),
            new Reference(self::SERVICE_INDEX_DEFINITION),
            new TaggedIteratorArgument(self::TAG_ENRICHER),
        ]))->addTag('console.command'));
    }
}
