<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const ROOT = 'borsche_elasticsearch_audit';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT);

        $treeBuilder->getRootNode()
            ->validate()
                ->ifTrue(static fn (array $c) => ($c['client']['service'] ?? null) === null && ($c['client']['hosts'] ?? []) === [])
                ->thenInvalid('Set either client.hosts or client.service.')
            ->end()
            ->children()
                ->arrayNode('client')
                    ->info('How to reach Elasticsearch: either hosts to build a client from, or the id of a client service you already have.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('hosts')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->scalarNode('service')
                            ->info('Service id of an existing Elastic\Elasticsearch\Client. Takes precedence over hosts.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('ssl_verification')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('indices')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default')
                            ->info('Index every record goes to unless routed elsewhere.')
                            ->cannotBeEmpty()
                            ->defaultValue('audit_log')
                            ->validate()
                                ->ifTrue(self::invalidIndexName(...))
                                ->thenInvalid('%s is not a valid Elasticsearch index name: lowercase, no spaces or \\ / * ? " < > | , # :, and not starting with - _ +.')
                            ->end()
                        ->end()
                        ->arrayNode('routing')
                            ->info('Per object type index, e.g. { auth: audit_auth_log, warehouse_stock: audit_stock_log }.')
                            ->useAttributeAsKey('object_type')
                            ->scalarPrototype()
                                ->cannotBeEmpty()
                                ->validate()
                                    ->ifTrue(self::invalidIndexName(...))
                                    ->thenInvalid('%s is not a valid Elasticsearch index name: lowercase, no spaces or \\ / * ? " < > | , # :, and not starting with - _ +.')
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->enumNode('object_id_type')
                            ->info('How objectId is mapped: "keyword" fits any identifier (UUIDs, external ids); "integer" allows range queries on numeric ids.')
                            ->values([IndexDefinition::OBJECT_ID_KEYWORD, IndexDefinition::OBJECT_ID_INTEGER])
                            ->defaultValue(IndexDefinition::OBJECT_ID_KEYWORD)
                        ->end()
                        ->arrayNode('settings')
                            ->info('Index settings applied by audit:index:create.')
                            ->variablePrototype()->end()
                            ->defaultValue(['number_of_shards' => 1, 'number_of_replicas' => 0])
                        ->end()
                    ->end()
                ->end()
                ->enumNode('transport')
                    ->info('"sync" writes in the request; "messenger" dispatches a message and a worker writes it.')
                    ->values(['sync', 'messenger'])
                    ->defaultValue('sync')
                ->end()
                ->scalarNode('message_bus')
                    ->info('Service id of the bus to dispatch to when transport is "messenger".')
                    ->defaultValue('messenger.default_bus')
                ->end()
                ->enumNode('on_failure')
                    ->info('"log" (default): a failed write is logged and ignored. "throw": it raises WriteFailedException.')
                    ->values(array_map(static fn (FailurePolicy $p) => $p->value, FailurePolicy::cases()))
                    ->defaultValue(FailurePolicy::Log->value)
                ->end()
                ->arrayNode('actor')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('fallback')
                            ->info('Recorded as the actor when nobody is authenticated (workers, console commands).')
                            ->cannotBeEmpty()
                            ->defaultValue('system')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('reader')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('point_in_time_keep_alive')
                            ->info('How long a point in time opened by AuditReader::iterate() stays alive between two batches, e.g. "1m". Long enough for the slowest consumer of one batch.')
                            ->cannotBeEmpty()
                            ->defaultValue('1m')
                            ->validate()
                                ->ifTrue(static fn (mixed $v) => !\is_string($v) || preg_match('/^\d+(d|h|m|s|ms|micros|nanos)$/', $v) !== 1)
                                ->thenInvalid('%s is not an Elasticsearch time value (e.g. "30s", "1m", "2h").')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('redact')
                    ->info('Fields whose values are replaced before a record is written — passwords, tokens, personal data you must not keep.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('fields')
                            ->info('Field names, plainly ("password") or per object type ("user.email").')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->scalarNode('placeholder')
                            ->info('What the value is replaced with. A side that was null or empty is left as it was.')
                            ->cannotBeEmpty()
                            ->defaultValue('***')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('coalescing')
                    ->info('Merging the records one business operation produces across several saves into one per object (AuditFrame).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('false: frames still open and close, but hold nothing — every save is its own record again.')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('object_types')
                            ->info('Object types held while a frame is open; empty means every type.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('numeric_fields')
                            ->info('Fields for which null, "" and 0 count as the same value when deciding whether anything changed. "quantity" for every object type, "stock.quantity" for one.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('max_held')
                            ->info('Objects a frame may hold before it releases what it has (a safety valve for runaway frames).')
                            ->min(1)
                            ->defaultValue(10000)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->info('Automatic auditing of entities implementing AuditableInterface or marked #[Auditable]. Needs doctrine/orm.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->booleanNode('skip_empty_updates')
                            ->info('Do not record an update whose audited fields did not change.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('connection')
                            ->info('Doctrine connection name the listener is attached to.')
                            ->defaultValue('default')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * Elasticsearch's own rules for index names, checked at compile time rather than
     * on the first write. Wildcards are refused too: an index the bundle writes to
     * has to be one concrete index.
     */
    private static function invalidIndexName(mixed $name): bool
    {
        if (!\is_string($name)) {
            return true;
        }

        return $name === '.' || $name === '..'
            || \strlen($name) > 255
            || \in_array($name[0], ['-', '_', '+'], true)
            || preg_match('/[A-Z\s\\\\\/*?"<>|,#:]/', $name) === 1;
    }
}
