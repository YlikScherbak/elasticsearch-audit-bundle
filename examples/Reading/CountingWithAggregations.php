<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Reading;

use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;

/**
 * Questions a page of records cannot answer: how many, by whom, per day.
 *
 * `raw()` sends a body of your own and returns the cluster's answer as it is —
 * with the query's filters, and every visibility boundary a `QueryExtension`
 * applied, wrapped around it. That wrapping is the whole promise of the method,
 * which is also why the body is checked rather than forwarded: a `global`
 * aggregation is *defined* to ignore the query and count the whole index, so a
 * number in a response that wears a boundary would quietly not be bounded by it.
 * Bodies carrying such a shape are refused.
 *
 * Read the response defensively. A viewer who may see nothing gets an empty hits
 * envelope and no `aggregations` key at all, so `?? []` is not politeness here —
 * it is the difference between a screen that shows zero and one that breaks
 * exactly for the person with the least access.
 */
final class CountingWithAggregations
{
    public function __construct(private readonly AuditReader $reader)
    {
    }

    /**
     * Who touched orders this month, and how often.
     *
     * @return array<string, int> actor => records
     */
    public function busiestActors(\DateTimeImmutable $since): array
    {
        $response = $this->reader->raw(
            AuditQuery::for('order')->since($since),
            [
                'size' => 0,                      // the counts, not the records
                'track_total_hits' => true,
                'aggs' => [
                    'by_actor' => [
                        'terms' => ['field' => 'source', 'size' => 20],
                    ],
                ],
            ],
        );

        $counts = [];

        /** @var array<int, array{key?: mixed, doc_count?: mixed}> $buckets */
        $buckets = $response['aggregations']['by_actor']['buckets'] ?? [];

        foreach ($buckets as $bucket) {
            if (\is_scalar($bucket['key'] ?? null) && \is_int($bucket['doc_count'] ?? null)) {
                $counts[(string) $bucket['key']] = $bucket['doc_count'];
            }
        }

        return $counts;
    }

    /**
     * A histogram for a chart — one bucket per day, over the same boundary.
     *
     * @return array<string, int> day => records
     */
    public function perDay(\DateTimeImmutable $since): array
    {
        $response = $this->reader->raw(
            AuditQuery::any()->since($since),
            [
                'size' => 0,
                'aggs' => [
                    'per_day' => [
                        'date_histogram' => [
                            'field' => 'loggedAt',
                            'calendar_interval' => 'day',
                            'format' => 'yyyy-MM-dd',
                        ],
                    ],
                ],
            ],
        );

        $days = [];

        /** @var array<int, array{key_as_string?: mixed, doc_count?: mixed}> $buckets */
        $buckets = $response['aggregations']['per_day']['buckets'] ?? [];

        foreach ($buckets as $bucket) {
            if (\is_string($bucket['key_as_string'] ?? null) && \is_int($bucket['doc_count'] ?? null)) {
                $days[$bucket['key_as_string']] = $bucket['doc_count'];
            }
        }

        return $days;
    }
}
