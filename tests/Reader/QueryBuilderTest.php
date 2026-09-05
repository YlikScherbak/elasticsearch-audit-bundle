<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Asserts on the exact request body: this is where a wrong shape ("[terms_lookup]
 * unknown field", a nested array where Elasticsearch wants a list) would surface
 * as a 400 in production.
 */
final class QueryBuilderTest extends TestCase
{
    public function testAnUnfilteredQueryMatchesEverythingNewestFirst(): void
    {
        $body = (new QueryBuilder())->build(AuditQuery::any());

        self::assertEquals(['match_all' => new \stdClass()], $body['query']);
        self::assertSame([['loggedAt' => 'desc'], ['id' => ['order' => 'desc', 'unmapped_type' => 'keyword']], ['_index' => 'desc']], $body['sort'], 'the record id breaks ties: unlike _doc it does not move between refreshes; unmapped_type keeps reads working on an index created before ids existed. Across indices the pair is not unique, so the index name joins them');
        self::assertSame(0, $body['from']);
        self::assertSame(20, $body['size']);
        self::assertTrue($body['track_total_hits']);
        self::assertArrayNotHasKey('search_after', $body);
    }

    public function testEveryConditionIsAFilterClause(): void
    {
        $query = AuditQuery::for('order')
            ->withObjectIds(42)
            ->withEvents('create', 'update')
            ->withActors('7')
            ->withIds('abc')
            ->between(new \DateTimeImmutable('2026-08-26 00:00:00', new \DateTimeZone('Europe/Kyiv')), new \DateTimeImmutable('2026-08-27 00:00:00', new \DateTimeZone('UTC')))
            ->where('salesType', 3)
            ->whereIn('warehouseId', [1, 2]);

        $body = (new QueryBuilder())->build($query);

        self::assertSame([
            ['term' => ['objectType' => 'order']],
            ['term' => ['objectId' => 42]],
            ['terms' => ['event' => ['create', 'update']]],
            ['term' => ['source' => '7']],
            ['ids' => ['values' => ['abc']]],
            ['range' => ['loggedAt' => ['gte' => '2026-08-25 21:00:00', 'lte' => '2026-08-27 00:00:00']]],
            ['term' => ['salesType' => 3]],
            ['terms' => ['warehouseId' => [1, 2]]],
        ], $body['query']['bool']['filter']);
    }

    public function testExistsMissingAndRangeFiltersHaveTheirClauses(): void
    {
        // The three shapes term/terms could not say: "has the field", "does not have
        // it" (a backfill looking for records written before an enricher existed), and
        // a range over an attribute.
        $query = AuditQuery::for('order')
            ->whereExists('orderCountry')
            ->whereNotExists('legacyRef')
            ->whereBetween('total', 100, 500);

        $body = (new QueryBuilder())->build($query);

        self::assertSame([
            ['term' => ['objectType' => 'order']],
            ['exists' => ['field' => 'orderCountry']],
            ['bool' => ['must_not' => [['exists' => ['field' => 'legacyRef']]]]],
            ['range' => ['total' => ['gte' => 100, 'lte' => 500]]],
        ], $body['query']['bool']['filter']);
    }

    public function testAnAttributeRangeCanBeHalfOpenAndSpeakDates(): void
    {
        $query = AuditQuery::for('order')->whereBetween('paidAt', new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('Europe/Kyiv')), null);

        $body = (new QueryBuilder())->build($query);

        self::assertSame(
            ['range' => ['paidAt' => ['gte' => '2025-12-31 22:00:00']]],
            $body['query']['bool']['filter'][1],
            'a date bound is stored the way the writer stores dates: UTC, in the index format'
        );
    }

    public function testAQueryThatMatchesNothingSaysSoInItsBody(): void
    {
        // Never sent by AuditReader, which answers such a query without a request —
        // but anything else that builds the body (raw(), a future caller) must not
        // quietly turn "known empty" back into a real search.
        $body = (new QueryBuilder())->build(AuditQuery::for('order')->withObjectIds(1)->matchNothing());

        self::assertEquals(['match_none' => new \stdClass()], $body['query']);
    }

    public function testAnOpenDateRangeOnlyHasOneBound(): void
    {
        $body = (new QueryBuilder())->build(AuditQuery::any()->since(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))));

        self::assertSame([['range' => ['loggedAt' => ['gte' => '2026-01-01 00:00:00']]]], $body['query']['bool']['filter']);
    }

    public function testPagingByOffset(): void
    {
        $body = (new QueryBuilder())->build(AuditQuery::any()->page(3, 50)->oldestFirst());

        self::assertSame(100, $body['from']);
        self::assertSame(50, $body['size']);
        self::assertSame([['loggedAt' => 'asc'], ['id' => ['order' => 'asc', 'unmapped_type' => 'keyword']], ['_index' => 'asc']], $body['sort']);
    }

    public function testPagingByCursorSendsSearchAfterInsteadOfFrom(): void
    {
        $body = (new QueryBuilder())->build(AuditQuery::any()->after(['2026-08-26 10:00:00', 17]));

        self::assertSame(['2026-08-26 10:00:00', 17], $body['search_after']);
        self::assertArrayNotHasKey('from', $body);
    }

    public function testOptionsNeverReachElasticsearch(): void
    {
        $body = (new QueryBuilder())->build(AuditQuery::for('order')->withOption('country', 'UA'));

        self::assertStringNotContainsString('country', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function testOneObjectTypeIsOneIndexAndNeedsNoIndexTiebreaker(): void
    {
        // The pair is unique inside an index; the third value is the price of reading
        // across several, and a query that does not is not asked to pay it.
        $body = (new QueryBuilder())->build(AuditQuery::for('order'));

        self::assertSame([['loggedAt' => 'desc'], ['id' => ['order' => 'desc', 'unmapped_type' => 'keyword']]], $body['sort']);
    }
}
