<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @internal the configuration tree; the settings themselves are documented in the README
 */
final class Configuration implements ConfigurationInterface
{
    public const ROOT = 'borsche_elasticsearch_audit';

    private const INVALID_INDEX_NAME = '%s is not a valid Elasticsearch index name: lowercase, no spaces or \\ / * ? " < > | , # :, and not starting with - _ +.';

    /**
     * The tree is built section by section rather than as one chain of end() calls.
     * A child is attached to its parent the moment it is created, so end() only walks
     * back up — and on the oldest supported symfony/config its return type includes
     * null, which makes a long chain unanalysable. Sections read better anyway.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT);
        $root = $treeBuilder->getRootNode();

        // The builder's return type widened only in newer symfony/config; on the oldest
        // supported version it is the parent class, which has no children().
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The root of a configuration tree is an array node.');
        }

        $root->validate()
            ->ifTrue(static fn (array $c) => ($c['client']['service'] ?? null) === null && ($c['client']['hosts'] ?? []) === [])
            ->thenInvalid('Set either client.hosts or client.service.');

        $children = $root->children();

        self::client($children->arrayNode('client'));
        self::indices($children->arrayNode('indices'));

        $children->enumNode('transport')
            ->info('"sync" writes in the request; "messenger" dispatches a message and a worker writes it.')
            ->values(['sync', 'messenger'])
            ->defaultValue('sync');

        $children->scalarNode('message_bus')
            ->info('Service id of the bus to dispatch to when transport is "messenger".')
            ->defaultValue('messenger.default_bus')
            ->validate()
                ->ifTrue(static fn (mixed $v) => !\is_string($v) || $v === '')
                ->thenInvalid('message_bus must be the id of a bus, not %s.')
            ->end();

        $children->integerNode('batch_size')
            ->info('How many records travel in one _bulk request or one Messenger message. A flush that produced more is split; a batch refused whole for being too large would lose every record in it.')
            ->min(1)
            ->defaultValue(500);

        $children->enumNode('on_failure')
            ->info('"log" (default): a failed write is logged and ignored. "throw": it raises WriteFailedException.')
            ->values(array_map(static fn (FailurePolicy $p) => $p->value, FailurePolicy::cases()))
            ->defaultValue(FailurePolicy::Log->value);

        self::actor($children->arrayNode('actor'));
        self::reader($children->arrayNode('reader'));
        self::redact($children->arrayNode('redact'));
        self::coalescing($children->arrayNode('coalescing'));
        self::doctrine($children->arrayNode('doctrine'));

        return $treeBuilder;
    }

    private static function client(ArrayNodeDefinition $client): void
    {
        $client
            ->info('How to reach Elasticsearch: either hosts to build a client from, or the id of a client service you already have.')
            ->addDefaultsIfNotSet();

        $children = $client->children();

        $children->arrayNode('hosts')
            ->defaultValue([])
            ->scalarPrototype()->cannotBeEmpty();

        $children->scalarNode('service')
            ->info('Service id of an existing Elastic\Elasticsearch\Client. Takes precedence over hosts.')
            ->defaultNull()
            ->validate()
                // Not just a bad value: the root rule asks whether this is null, so an
                // empty string counted as "a client is configured" and a configuration
                // with neither hosts nor a usable service passed as valid.
                ->ifTrue(static fn (mixed $v) => $v !== null && (!\is_string($v) || $v === ''))
                ->thenInvalid('client.service must be the id of a service, not %s.')
            ->end();

        $children->booleanNode('ssl_verification')->defaultTrue();
    }

    private static function indices(ArrayNodeDefinition $indices): void
    {
        $indices->addDefaultsIfNotSet();

        $children = $indices->children();

        $children->scalarNode('default')
            ->info('Index every record goes to unless routed elsewhere.')
            ->cannotBeEmpty()
            ->defaultValue('audit_log')
            ->validate()
                ->ifTrue(self::invalidIndexName(...))
                ->thenInvalid(self::INVALID_INDEX_NAME);

        $routing = $children->arrayNode('routing');
        $routing
            ->info('Per object type index, e.g. { auth: audit_auth_log, warehouse-stock: audit_stock_log }.')
            // An object type is a name the application chose, not a configuration key to
            // tidy up: without this a dash becomes an underscore here and the writer,
            // arriving with the name it was given, finds no route and writes to the
            // default index without a word.
            ->normalizeKeys(false)
            ->useAttributeAsKey('object_type')
            ->defaultValue([]);
        $routing->scalarPrototype()
            ->cannotBeEmpty()
            ->validate()
                ->ifTrue(self::invalidIndexName(...))
                ->thenInvalid(self::INVALID_INDEX_NAME);

        $children->enumNode('object_id_type')
            ->info('How objectId is mapped: "keyword" fits any identifier (UUIDs, external ids); "long" allows range queries on numeric ids; "integer" does too but stops at 2147483647, which a bigint key outgrows.')
            ->values([IndexDefinition::OBJECT_ID_KEYWORD, IndexDefinition::OBJECT_ID_INTEGER, IndexDefinition::OBJECT_ID_LONG])
            ->defaultValue(IndexDefinition::OBJECT_ID_KEYWORD);

        $children->arrayNode('settings')
            ->info('Index settings applied by audit:index:create. One replica by default: an audit trail is the last data anyone wants on a single node. Set number_of_replicas to 0 for a one-node development cluster, where a replica can never be assigned.')
            ->defaultValue(['number_of_shards' => 1, 'number_of_replicas' => 1])
            ->variablePrototype();
    }

    private static function actor(ArrayNodeDefinition $actor): void
    {
        $actor->addDefaultsIfNotSet();

        $actor->children()->scalarNode('fallback')
            ->info('Recorded as the actor when nobody is authenticated (workers, console commands).')
            ->cannotBeEmpty()
            ->defaultValue('system');
    }

    private static function reader(ArrayNodeDefinition $reader): void
    {
        $reader
            ->addDefaultsIfNotSet()
            // A page larger than the deepest reachable row is a size no query can ever
            // use: the reader would allow the page and then refuse it for depth. A
            // configuration that contradicts itself should say so at boot.
            ->validate()
                ->ifTrue(static fn (array $r): bool => \is_int($r['max_limit'] ?? null) && \is_int($r['max_result_window'] ?? null) && $r['max_limit'] > $r['max_result_window'])
                ->thenInvalid('reader.max_limit cannot be larger than reader.max_result_window: a page that size could never be read.')
            ->end();

        $children = $reader->children();

        $children->integerNode('max_limit')
            ->info('Largest page AuditReader will read. Raise it for screens that show thousands of rows at once, and remember a decorator then receives that many entries in one call.')
            ->min(1)
            ->defaultValue(AuditQuery::DEFAULT_MAX_LIMIT);

        $children->integerNode('max_result_window')
            ->info('How deep page/limit may reach. Must not exceed the cluster\'s own index.max_result_window — raise both together, or page with a cursor, which has no ceiling.')
            ->min(1)
            ->defaultValue(AuditQuery::DEFAULT_MAX_WINDOW);

        $children->scalarNode('point_in_time_keep_alive')
            ->info('How long a point in time opened by AuditReader::iterate() stays alive between two batches, e.g. "1m". Long enough for the slowest consumer of one batch.')
            ->cannotBeEmpty()
            ->defaultValue('1m')
            ->validate()
                ->ifTrue(static fn (mixed $v) => !\is_string($v) || preg_match('/^\d+(d|h|m|s|ms|micros|nanos)$/', $v) !== 1)
                ->thenInvalid('%s is not an Elasticsearch time value (e.g. "30s", "1m", "2h").');
    }

    private static function redact(ArrayNodeDefinition $redact): void
    {
        $redact
            ->info('Fields whose values are replaced before a record is written — passwords, tokens, personal data you must not keep.')
            ->addDefaultsIfNotSet();

        $children = $redact->children();

        $children->arrayNode('fields')
            ->info('Field names, plainly ("password") or per object type ("user.email").')
            ->defaultValue([])
            ->scalarPrototype()->cannotBeEmpty();

        $children->scalarNode('placeholder')
            ->info('What the value is replaced with. A side that was null or empty is left as it was.')
            ->cannotBeEmpty()
            ->defaultValue('***')
            ->validate()
                ->ifTrue(static fn (mixed $v) => !\is_string($v))
                ->thenInvalid('redact.placeholder must be a string, not %s.')
            ->end();

        $children->enumNode('failure_details')
            ->info('How much of a failed write\'s cause the bundle repeats in the log line and in RecordFailedEvent. "cause": the class, plus messages the bundle wrote itself — the original stays reachable through WriteFailedException::getPrevious(). "full": the cause\'s message too. Left unset it follows redact.fields: configured means "cause", because a cluster or an enricher may quote a value you asked never to keep.')
            ->values(['cause', 'full'])
            ->defaultNull();
    }

    private static function coalescing(ArrayNodeDefinition $coalescing): void
    {
        $coalescing
            ->info('Merging the records one business operation produces across several saves into one per object (AuditFrame).')
            ->addDefaultsIfNotSet();

        $children = $coalescing->children();

        $children->booleanNode('enabled')
            ->info('false: frames still open and close, but hold nothing — every save is its own record again.')
            ->defaultTrue();

        $children->arrayNode('object_types')
            ->info('Object types held while a frame is open; empty means every type.')
            ->defaultValue([])
            ->scalarPrototype()->cannotBeEmpty();

        $children->arrayNode('numeric_fields')
            ->info('Fields for which null, "" and 0 count as the same value when deciding whether anything changed. "quantity" for every object type, "stock.quantity" for one.')
            ->defaultValue([])
            ->scalarPrototype()->cannotBeEmpty();

        $children->integerNode('max_held')
            ->info('Objects a frame may hold before the valve opens (a safety valve for runaway frames).')
            ->min(1)
            ->defaultValue(10000);

        $children->enumNode('on_overflow')
            ->info('What the valve does: "release" (default) writes what the frame holds and carries on, which keeps every record but ends the promise of one record per object; "throw" refuses the operation instead, for a trail that is read for that promise.')
            ->values(['release', 'throw'])
            ->defaultValue('release');
    }

    private static function doctrine(ArrayNodeDefinition $doctrine): void
    {
        $doctrine
            ->info('Automatic auditing of entities implementing AuditableInterface or marked #[Auditable]. Needs doctrine/orm.')
            ->addDefaultsIfNotSet();

        $children = $doctrine->children();

        // "auto" and not a boolean default, so that an explicit true can be told apart:
        // whoever wrote it is counting on entity auditing, and without doctrine/orm the
        // honest answer is a boot failure — a silent skip surfaces months later, as the
        // history that was never written.
        $children->enumNode('enabled')
            ->info('true requires doctrine/orm and fails the boot without it; "auto" (default) attaches the listener when doctrine/orm is installed and stays quiet when not.')
            ->values(['auto', true, false])
            ->defaultValue('auto');

        $children->booleanNode('skip_empty_updates')
            ->info('Do not record an update whose audited fields did not change.')
            ->defaultTrue();

        $children->scalarNode('connection')
            ->info('Doctrine connection name the listener is attached to.')
            ->defaultValue('default')
            ->validate()
                ->ifTrue(static fn (mixed $v) => !\is_string($v) || $v === '')
                ->thenInvalid('doctrine.connection must be a connection name, not %s.')
            ->end();
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
