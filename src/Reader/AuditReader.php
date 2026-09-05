<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Reader;

use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditPage;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;

/**
 * The one entry point for reading history.
 *
 * Runs the application's query extensions, builds the request, reads the index the
 * object type routes to, and hands the page to the decorators. Unlike the writer
 * it does not swallow failures: a history screen that cannot reach the cluster
 * should say so.
 */
final class AuditReader
{
    /**
     * @param iterable<QueryExtensionInterface>  $extensions
     * @param iterable<RecordDecoratorInterface> $decorators
     */
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly IndexResolver $indexResolver,
        private readonly QueryBuilder $queryBuilder = new QueryBuilder(),
        private readonly iterable $extensions = [],
        private readonly iterable $decorators = [],
        private readonly string $pointInTimeKeepAlive = '1m',
        private readonly int $maxLimit = AuditQuery::DEFAULT_MAX_LIMIT,
        private readonly int $maxResultWindow = AuditQuery::DEFAULT_MAX_WINDOW,
    ) {
    }

    /**
     * @throws InvalidQueryException          the query is invalid, or Elasticsearch rejected it (a stale cursor, say)
     * @throws IndexNotFoundException
     * @throws TransportUnavailableException
     */
    public function find(AuditQuery $query): AuditPage
    {
        $query = $this->extend($query);
        $this->assertWithinLimits($query);

        // Known emptiness — an extension whose visibility boundary is disjoint from
        // what was asked — is an answer, not a search: an empty page, no request, and
        // no cursor pointing into the void.
        if ($query->matchesNothing()) {
            return new AuditPage([], 0, $query->page, $query->limit, $query->usesCursor(), $this->maxResultWindow, fetched: 0, cursor: []);
        }

        $response = $this->gateway->search($this->indexFor($query), $this->queryBuilder->build($query));

        $hits = array_values($response['hits']['hits'] ?? []);
        $entries = array_map(AuditEntry::fromHit(...), $hits);
        $total = $response['hits']['total']['value'] ?? \count($entries);

        // Whether more follows, and where to continue, follow what Elasticsearch returned —
        // a decorator that hides entries must not end a "load more" early or skip past them.
        $lastSort = $hits === [] ? [] : ($hits[array_key_last($hits)]['sort'] ?? []);

        return new AuditPage(
            $this->decorate($entries),
            (int) $total,
            $query->page,
            $query->limit,
            $query->usesCursor(),
            $this->maxResultWindow,
            fetched: \count($hits),
            cursor: \is_array($lastSort) ? array_values($lastSort) : [],
        );
    }

    /**
     * Every matching entry, oldest or newest first as the query says, fetched in
     * batches with a cursor — no 10 000-row ceiling. For exports and backfills.
     *
     * @return \Generator<int, AuditEntry>
     *
     * @throws InvalidQueryException
     * @throws IndexNotFoundException
     * @throws TransportUnavailableException
     */
    public function iterate(AuditQuery $query, int $batchSize = 500, bool $consistent = true): \Generator
    {
        $query = $this->extend($query);

        // Not a silent restart, which hid the caller's mistake: a consistent traversal
        // opens its own point in time, and the sort values of an earlier one carry
        // _shard_doc, which means nothing inside a different view — and is a third
        // value where a plain search has two.
        if ($query->usesCursor() || $query->page !== 1) {
            throw new InvalidQueryException('iterate() starts a traversal of its own and cannot continue from a page or a cursor: the point in time it opens is not the one those values came from. Pass an unpaged query, and narrow it if you need to resume where an export stopped.');
        }

        $query = $query->page(1, $batchSize);
        $this->assertWithinLimits($query);

        if ($query->matchesNothing()) {
            return; // a traversal of nothing: no batches, and no point in time opened for them
        }

        $index = $this->indexFor($query);

        // A point in time freezes what the export sees: records written while it runs do
        // not appear, and no record appears twice because a segment merged underneath.
        // Closed in finally, which a generator runs when the consumer stops early too.
        $pit = $consistent ? $this->gateway->openPointInTime($index, $this->pointInTimeKeepAlive) : null;

        try {
            while (true) {
                $response = $pit === null
                    // No total: iterate() never reads one, and an exact count of the whole
                    // result set on every batch is a full pass over the index per page.
                    ? $this->gateway->search($index, $this->queryBuilder->build($query, trackTotalHits: false))
                    : $this->gateway->searchPointInTime($pit, $this->pointInTimeKeepAlive, $this->queryBuilder->build($query, pointInTime: true, trackTotalHits: false));

                // Elasticsearch may hand back a renewed id; the next search must use it.
                if ($pit !== null && \is_string($response['pit_id'] ?? null) && $response['pit_id'] !== '') {
                    $pit = $response['pit_id'];
                }

                $hits = array_values($response['hits']['hits'] ?? []);

                if ($hits === []) {
                    return;
                }

                // The cursor and the stop condition follow what Elasticsearch returned, so
                // a decorator that drops entries from the page cannot end the export early.
                // One by one, not yield from: that keeps the batch's own 0..n keys, and
                // iterator_to_array() without false then overwrites every batch with the
                // next — of a five-record export, two survived.
                foreach ($this->decorate(array_map(AuditEntry::fromHit(...), $hits)) as $entry) {
                    yield $entry;
                }

                $cursor = $hits[array_key_last($hits)]['sort'] ?? [];

                if (\count($hits) < $batchSize || !\is_array($cursor) || $cursor === []) {
                    return; // last batch, or no sort values to continue from
                }

                $query = $query->after($cursor);
            }
        } finally {
            if ($pit !== null) {
                $this->gateway->closePointInTime($pit);
            }
        }
    }

    /**
     * The escape hatch: a raw request body — aggregations, a shape find() cannot say —
     * that still wears everything the reader guarantees. The QueryExtensions run, the
     * query's filters become the request's boundary, and the index is the one the
     * query routes to; without this, every consumer needing one "who changed this
     * most" reaches for the bare client and silently loses the visibility narrowing.
     *
     * The body is the caller's: sort, size, aggs travel as given. A "query" the body
     * carries is kept, wrapped inside the boundary (it can narrow further, never
     * widen). Decorators do not run — what comes back is Elasticsearch's response,
     * whose hits need not be audit entries at all.
     *
     * A query known to match nothing is answered without a request, and that answer
     * carries **hits only — no "aggregations" key**: an empty bucket list cannot be
     * synthesised without knowing which aggregation was asked for. Read the response
     * defensively (`$response['aggregations']['by']['buckets'] ?? []`), or the code
     * breaks exactly when a viewer may see nothing.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> the raw response; for a query known to match
     *                              nothing, an empty hits envelope and nothing else
     *
     * @throws InvalidQueryException
     * @throws IndexNotFoundException
     * @throws TransportUnavailableException
     */
    public function raw(AuditQuery $query, array $body): array
    {
        self::assertBodyStaysInsideTheBoundary($body);

        $query = $this->extend($query);

        if ($query->matchesNothing()) {
            return ['hits' => ['total' => ['value' => 0, 'relation' => 'eq'], 'hits' => []]];
        }

        $this->assertWithinLimits($query->limit(
            \is_int($body['size'] ?? null) && $body['size'] > 0 ? $body['size'] : 1
        )->page(
            \is_int($body['from'] ?? null) && $body['from'] > 0 && \is_int($body['size'] ?? null) && $body['size'] > 0
                ? intdiv($body['from'], max(1, $body['size'])) + 1
                : 1,
            \is_int($body['size'] ?? null) && $body['size'] > 0 ? $body['size'] : 1,
        ));

        $boundary = $this->queryBuilder->build($query)['query'];

        $body['query'] = isset($body['query'])
            ? ['bool' => ['filter' => [$boundary], 'must' => [$body['query']]]]
            : $boundary;

        return $this->gateway->search($this->indexFor($query), $body);
    }

    /**
     * What a raw body may contain, and why it is a list rather than "anything".
     *
     * Putting the query's filters into `query` makes a request narrower only for the
     * parts of the Search API that read the query. Several do not: a `global`
     * aggregation is *defined* to ignore it and count the whole search context, and
     * `knn` is combined with the query by union rather than intersection. A body
     * carrying either would wear a visibility boundary that some of its own numbers
     * ignore — the one thing this method promises not to do — so the shapes the reader
     * can vouch for are named, and everything else is refused rather than forwarded on
     * the hope that it behaves.
     *
     * @param array<string, mixed> $body
     *
     * @throws InvalidQueryException
     */
    private static function assertBodyStaysInsideTheBoundary(array $body): void
    {
        static $allowed = [
            'query', 'aggs', 'aggregations', 'size', 'from', 'sort', 'search_after',
            '_source', 'fields', 'docvalue_fields', 'stored_fields', 'script_fields',
            'runtime_mappings', 'track_total_hits', 'post_filter', 'collapse',
            'highlight', 'min_score', 'timeout', 'terminate_after', 'explain', 'version', 'seq_no_primary_term',
        ];

        foreach (array_keys($body) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new InvalidQueryException(sprintf('raw() cannot carry "%s": what it guarantees is that the query\'s filters bound the request, and only the parts of the Search API that read the query can be bounded that way. Allowed here: %s.', $key, implode(', ', $allowed)));
            }
        }

        foreach (['aggs', 'aggregations'] as $key) {
            if (\is_array($body[$key] ?? null)) {
                self::refuseGlobalAggregations($body[$key], $key);
            }
        }
    }

    /**
     * At any depth: an aggregation nested three levels down escapes the query exactly
     * as thoroughly as one at the top.
     *
     * @param array<mixed> $aggregations
     *
     * @throws InvalidQueryException
     */
    private static function refuseGlobalAggregations(array $aggregations, string $path): void
    {
        foreach ($aggregations as $name => $aggregation) {
            if (!\is_array($aggregation)) {
                continue;
            }

            $here = $path.'.'.(string) $name;

            if (\array_key_exists('global', $aggregation)) {
                throw new InvalidQueryException(sprintf('The aggregation at "%s" is global, and a global aggregation ignores the query by definition: it would count records the query — and any visibility rule on it — excludes. Aggregate inside the query, or ask the cluster directly if you really mean "everything".', $here));
            }

            foreach (['aggs', 'aggregations'] as $key) {
                if (\is_array($aggregation[$key] ?? null)) {
                    self::refuseGlobalAggregations($aggregation[$key], $here.'.'.$key);
                }
            }
        }
    }

    /**
     * How big a page may be, and how deep from/size may reach, is a property of the
     * deployment: the second one has to match the cluster's own index.max_result_window,
     * which an operator raises when the screens need deeper pages. Checked here, after
     * the extensions have had their say, so what runs is what was checked — and before
     * anything reaches Elasticsearch, so the message names the setting rather than
     * arriving as a 400 from the cluster.
     *
     * A cursor is not bounded by the window at all; only its page size is checked.
     */
    private function assertWithinLimits(AuditQuery $query): void
    {
        if ($query->limit > $this->maxLimit) {
            throw new InvalidQueryException(sprintf('A page of %d is larger than reader.max_limit (%d). Raise the setting, or read in smaller pages.', $query->limit, $this->maxLimit));
        }

        if ($query->usesCursor()) {
            return;
        }

        if ($query->page * $query->limit > $this->maxResultWindow) {
            throw new InvalidQueryException(sprintf('Page %d with %d per page reaches row %d, past reader.max_result_window (%d). Raise it here and index.max_result_window on the cluster to match, or page with a cursor — after() has no such ceiling.', $query->page, $query->limit, $query->page * $query->limit, $this->maxResultWindow));
        }
    }

    private function extend(AuditQuery $query): AuditQuery
    {
        foreach ($this->extensions as $extension) {
            $query = $extension->extend($query);
        }

        return $query;
    }

    private function indexFor(AuditQuery $query): string
    {
        // any(): every index the configuration routes to, as one multi-index search,
        // so a type living in its own index is not silently left out of "everything".
        return $query->objectType === null
            ? implode(',', $this->indexResolver->all())
            : $this->indexResolver->resolve($query->objectType);
    }

    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<AuditEntry>
     */
    private function decorate(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        foreach ($this->decorators as $decorator) {
            $entries = array_values($decorator->decorate($entries));
        }

        return $entries;
    }
}
