<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\QueryBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * The 0.12 additions against the real cluster: putMapping's merge semantics (a
 * partial parent must not touch siblings), the refusal to change a live type,
 * the shape settings() comes back in, and the new query clauses — exists,
 * missing, range — accepted by a real search.
 */
#[Group('integration')]
final class MappingSyncOnLiveClusterTest extends ElasticsearchTestCase
{
    private ElasticsearchGateway $gateway;
    private string $index;

    protected function setUp(): void
    {
        $this->index = $this->scratchIndex();
        $this->gateway = new ElasticsearchGateway(self::client());
        $this->gateway->createIndex($this->index, (new IndexDefinition())->withProperties([
            'context' => ['properties' => ['ip' => ['type' => 'ip']]],
        ])->toArray());
    }

    protected function tearDown(): void
    {
        $this->dropIndex($this->index);
    }

    public function testPutMappingAddsWithoutTouchingSiblings(): void
    {
        $this->gateway->putMapping($this->index, [
            'orderCountry' => ['type' => 'keyword'],
            'context' => ['properties' => ['city' => ['type' => 'keyword']]],
        ]);

        $mapping = $this->gateway->mapping($this->index);

        self::assertSame(['type' => 'keyword'], $mapping['orderCountry']);
        self::assertSame(['type' => 'keyword'], $mapping['context']['properties']['city']);
        self::assertSame(['type' => 'ip'], $mapping['context']['properties']['ip'], 'the partial parent merged; the sibling stands');
    }

    public function testPutMappingRefusesToChangeALiveType(): void
    {
        $this->expectException(RequestRejectedException::class);

        $this->gateway->putMapping($this->index, ['loggedAt' => ['type' => 'keyword']]);
    }

    public function testSettingsComeBackKeyedByConcreteIndex(): void
    {
        $settings = $this->gateway->settings($this->index);

        self::assertArrayHasKey($this->index, $settings);
        // Elasticsearch answers with strings; whoever compares must cast — this pins it.
        self::assertSame('1', $settings[$this->index]['number_of_shards']);
    }

    public function testTheNewClausesAreAcceptedByARealSearch(): void
    {
        $this->gateway->putMapping($this->index, ['orderCountry' => ['type' => 'keyword'], 'total' => ['type' => 'integer']]);
        $this->gateway->index($this->index, ['objectType' => 'order', 'objectId' => 1, 'event' => 'create', 'loggedAt' => '2026-09-01 10:00:00', 'orderCountry' => 'UA', 'total' => 150]);
        $this->gateway->index($this->index, ['objectType' => 'order', 'objectId' => 2, 'event' => 'create', 'loggedAt' => '2026-09-01 10:00:01', 'total' => 900], refresh: true);

        $builder = new QueryBuilder();

        $missing = $this->gateway->search($this->index, $builder->build(AuditQuery::for('order')->whereNotExists('orderCountry')));
        self::assertSame([2], array_column(array_column($missing['hits']['hits'], '_source'), 'objectId'), 'the record written before the enricher existed');

        $exists = $this->gateway->search($this->index, $builder->build(AuditQuery::for('order')->whereExists('orderCountry')));
        self::assertSame([1], array_column(array_column($exists['hits']['hits'], '_source'), 'objectId'));

        $range = $this->gateway->search($this->index, $builder->build(AuditQuery::for('order')->whereBetween('total', 100, 500)));
        self::assertSame([1], array_column(array_column($range['hits']['hits'], '_source'), 'objectId'));

        $nothing = $this->gateway->search($this->index, $builder->build(AuditQuery::for('order')->matchNothing()));
        self::assertSame(0, $nothing['hits']['total']['value'], 'match_none is a body the cluster accepts');
    }

    public function testAnIndexThatWouldLetElasticsearchInventFieldsIsReported(): void
    {
        // dynamic: false is stated as a guarantee — a field nobody declared is stored
        // and not indexed — and until now nothing could check it: mapping() answers with
        // the properties and drops everything around them, so an index created by hand,
        // a changed template or a new member of an alias could be wide open with every
        // declared field mapped exactly right.
        $ours = $this->scratchIndex();
        $theirs = $this->scratchIndex();

        try {
            self::client()->indices()->create(['index' => $ours, 'body' => ['mappings' => ['dynamic' => false, 'properties' => ['objectType' => ['type' => 'keyword']]]]]);
            self::client()->indices()->create(['index' => $theirs, 'body' => ['mappings' => ['properties' => ['objectType' => ['type' => 'keyword']]]]]);

            $gateway = new ElasticsearchGateway(self::client());

            self::assertSame([], $gateway->indicesAcceptingUnknownFields($ours), 'the mapping this bundle creates is closed');
            self::assertSame([$theirs], $gateway->indicesAcceptingUnknownFields($theirs), 'and an index without it is named');
        } finally {
            $this->dropIndex($ours);
            $this->dropIndex($theirs);
        }
    }
}
