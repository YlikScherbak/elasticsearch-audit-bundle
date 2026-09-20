<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

/**
 * The aggregation allow-list, asked about every aggregation rather than the first one.
 *
 * What raw() guarantees is that the query's filters bound the request, and an
 * aggregation is the one part of a search body that can step outside them: `global`
 * ignores the query outright, `significant_terms` compares against the whole index,
 * `children` moves to another document scope. So the boundary is checked by walking the
 * body and reading every aggregation in it.
 *
 * Walking is the part this file is about. The loops that do it each skip something —
 * a key the body does not carry, a keyword that is not an aggregation type — and a skip
 * that stops the walk instead of continuing it reads one aggregation and calls the rest
 * checked. Elasticsearch accepts `aggs` and `aggregations` as the same thing, so the
 * body that gets through is not exotic: it is the one written with the long spelling.
 */
final class EveryAggregationIsReadTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->gateway->respondToSearch = static fn (): array => ['hits' => ['total' => ['value' => 0], 'hits' => []]];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function bodiesThatStepOutsideTheQuery(): iterable
    {
        $global = ['everything' => ['global' => new \stdClass()]];

        yield 'the short spelling at the top' => [['aggs' => $global], 'aggs.everything'];

        // The one the first loop would miss if it stopped instead of skipping: the body
        // carries no "aggs" at all, so the key before this one is absent.
        yield 'the long spelling at the top' => [['aggregations' => $global], 'aggregations.everything'];

        // And the one the innermost loop would miss, for the same reason one level down.
        yield 'the long spelling underneath' => [
            ['aggs' => ['by_actor' => ['terms' => ['field' => 'actor'], 'aggregations' => $global]]],
            'aggs.by_actor.aggregations.everything',
        ];

        yield 'the short spelling underneath' => [
            ['aggs' => ['by_actor' => ['terms' => ['field' => 'actor'], 'aggs' => $global]]],
            'aggs.by_actor.aggs.everything',
        ];

        // "meta" is a keyword rather than an aggregation type, so reading it means
        // skipping it and carrying on to the key that says what this aggregation is.
        // Stopping there instead leaves the type unread.
        yield 'behind a keyword' => [
            ['aggs' => ['everything' => ['meta' => ['note' => 'mine'], 'global' => new \stdClass()]]],
            'aggs.everything',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesThatStepOutsideTheQuery')]
    public function testAnAggregationThatIgnoresTheQueryIsRefusedWhereverItSits(array $body, string $path): void
    {
        try {
            $this->reader()->raw(AuditQuery::for('order'), ['size' => 0] + $body);
            self::fail('expected InvalidQueryException');
        } catch (InvalidQueryException $e) {
            // The path, not just the refusal: it is the whole of what tells somebody
            // holding a hundred-line body which aggregation to rewrite.
            self::assertStringContainsString(sprintf('The aggregation at "%s" is a "global"', $path), $e->getMessage());
        }
    }

    public function testAnAggregationTheAllowListNamesIsLetThrough(): void
    {
        // The other side of the same boundary: the check exists to let ordinary
        // aggregations past, and a test that only ever sees refusals cannot tell a
        // working allow-list from one that refuses everything.
        $this->reader()->raw(AuditQuery::for('order'), ['size' => 0, 'aggregations' => [
            'by_actor' => [
                'terms' => ['field' => 'actor'],
                'meta' => ['note' => 'mine'],
                'aggregations' => ['by_day' => ['date_histogram' => ['field' => 'loggedAt', 'calendar_interval' => 'day']]],
            ],
        ]]);

        self::assertCount(1, $this->gateway->searches);
    }

    private function reader(): AuditReader
    {
        return new AuditReader($this->gateway, new IndexResolver('audit_log'));
    }
}
