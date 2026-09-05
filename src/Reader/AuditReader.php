<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Reader;

use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\PartialResultException;
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
     * @throws PartialResultException         the cluster answered with less than it was asked for — a
     *                                        timeout, a shard that failed, a search cut short. A page of
     *                                        history that quietly leaves records out is worse than none
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

        self::assertTheCursorBelongsHere($query);

        $response = $this->gateway->search($this->indexFor($query), $this->queryBuilder->build($query));
        self::assertNothingWasMissed($response);

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
            // The effective query, extensions included: the token belongs to what was
            // actually searched, not to what the caller asked for before narrowing.
            query: $query->fingerprint(),
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
     * @throws PartialResultException         a batch came back incomplete; the entries already
     *                                        yielded are sound, and the export stops rather than
     *                                        finishing with a hole in it
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

                // Elasticsearch may hand back a renewed id, and from then on the view is
                // known by that one — including to the close in the finally below. Read
                // before the answer is judged: a partial or timed-out response carries
                // the new id too, and stopping here holding the previous one would leave
                // a view open, keeping its segments, until the keep-alive ran out.
                if ($pit !== null && \is_string($response['pit_id'] ?? null) && $response['pit_id'] !== '') {
                    $pit = $response['pit_id'];
                }

                // Before the hits are read and before the cursor moves: an incomplete
                // batch would hand its last hit over as the place to continue from, and
                // whatever the failed shard held earlier would never be read at all.
                self::assertNothingWasMissed($response);

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
     * @throws PartialResultException         the cluster answered with less than it was asked for —
     *                                        an aggregation over some of the shards is a number that
     *                                        looks exact and is not
     * @throws TransportUnavailableException
     */
    public function raw(AuditQuery $query, array $body): array
    {
        // Both checks before the shortcut, and for the same reason: a malformed body is
        // the caller's mistake whoever is looking at it. Validating paging after the
        // shortcut made `from: -1` throw for one viewer and pass in silence for another,
        // which is how a bug reaches production as "it works for me".
        self::assertBodyStaysInsideTheBoundary($body);
        $this->assertRawPagingIsWithinLimits($body);

        $query = $this->extend($query);

        if ($query->matchesNothing()) {
            return ['hits' => ['total' => ['value' => 0, 'relation' => 'eq'], 'hits' => []]];
        }

        $boundary = $this->queryBuilder->buildQuery($query);

        $body['query'] = isset($body['query'])
            ? ['bool' => ['filter' => [$boundary], 'must' => [$body['query']]]]
            : $boundary;

        $response = $this->gateway->search($this->indexFor($query), $body);

        // An aggregation over part of the index is a number that looks like an answer
        // and is not one — worse here than on a page, because nothing about a count
        // suggests it might be short.
        self::assertNothingWasMissed($response);

        return $response;
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
        // runtime_mappings is deliberately not here. A runtime field may carry the name
        // of a mapped one and shadows it for the whole query, so a body could define
        // `source` as a script emitting the very value the boundary filters on — and
        // the filter would then be true of every document in the index.
        static $allowed = [
            'query', 'aggs', 'aggregations', 'size', 'from', 'sort', 'search_after',
            '_source', 'fields', 'docvalue_fields', 'stored_fields',
            'track_total_hits', 'post_filter', 'collapse',
            'highlight', 'min_score', 'timeout', 'explain', 'version', 'seq_no_primary_term',
        ];

        // terminate_after is not here either, and for the other reason: it reads the
        // query like any ordinary parameter, and it makes the answer partial by
        // construction — the cluster stops counting and says terminated_early. This
        // reader answers with the records or with an exception, and a parameter whose
        // purpose is to return some of them cannot be part of that. `timeout` stays,
        // because timing out is reported and refused rather than served.

        foreach (array_keys($body) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new InvalidQueryException(sprintf('raw() cannot carry "%s": what it guarantees is that the query\'s filters bound the request, and only the parts of the Search API that read the query can be bounded that way. Allowed here: %s.', $key, implode(', ', $allowed)));
            }
        }

        foreach (['aggs', 'aggregations'] as $key) {
            if (!\array_key_exists($key, $body)) {
                continue;
            }

            if (!\is_array($body[$key])) {
                throw new InvalidQueryException(sprintf('raw() cannot vouch for "%s" given as %s: the aggregations are checked by reading them, so they have to be arrays.', $key, get_debug_type($body[$key])));
            }

            self::refuseGlobalAggregations($body[$key], $key);
        }
    }

    /**
     * Whether this answer is the whole answer.
     *
     * Elasticsearch returns what it has when a shard fails or a search runs out of
     * time, and says so in the response rather than by failing. For a search screen
     * that is often right; for an audit trail "these are the records" has to be true
     * or be an error.
     *
     * @param array<string, mixed> $response
     *
     * @throws PartialResultException
     */
    /**
     * A token continues the query it was issued for, and no other.
     *
     * The with*() family drops a cursor when the query changes underneath it, which
     * covers `after($c)->withEvents(...)`. A token arrives the other way round —
     * `withEvents(...)->afterToken($t)` — already detached from everything, and only
     * what the token carries can say where it belongs. Elasticsearch cannot tell: the
     * sort tuple has the right shape, so it answers with what follows that position in
     * whatever set is being searched, and every matching record before it is missing
     * without a word.
     *
     * Compared after the extensions have run, because what was searched is what the
     * token belongs to.
     *
     * @throws InvalidQueryException
     */
    private static function assertTheCursorBelongsHere(AuditQuery $query): void
    {
        $issuedFor = $query->continuedQuery();

        if ($issuedFor !== null && $issuedFor !== $query->fingerprint()) {
            throw new InvalidQueryException('This cursor token was issued for a different query — different filters, dates, options or sort order — and continuing it here would answer with the page after that position in this result set, silently skipping everything before it. Read this query from the first page, or continue the token on the query it came from.');
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function assertNothingWasMissed(array $response): void
    {
        if (($response['timed_out'] ?? false) === true) {
            throw PartialResultException::timedOut();
        }

        // Not only when a body asked for it — raw() refuses terminate_after — but
        // whenever the answer says it stopped early: an index-level setting can do it,
        // and so can whatever Elasticsearch adds next. The flag in the response is the
        // fact; who asked is not the reader's business.
        if (($response['terminated_early'] ?? false) === true) {
            throw PartialResultException::stoppedEarly();
        }

        $shards = \is_array($response['_shards'] ?? null) ? $response['_shards'] : [];
        $failed = (int) ($shards['failed'] ?? 0);

        if ($failed > 0) {
            throw PartialResultException::shardsFailed($failed, (int) ($shards['total'] ?? $failed));
        }
    }

    /**
     * The reader's limits, counted the way Elasticsearch counts: `from + size`.
     *
     * Reconstructing a page number out of a raw `from` and multiplying it back was
     * arithmetic that agreed with itself and not with the cluster — from 9999 with size
     * 2 reaches row 10001 and was allowed through as "page 5000 × 2". And a body with
     * no size at all is ten rows, not one: that is Elasticsearch's default, not a
     * convenient minimum.
     *
     * @param array<string, mixed> $body
     *
     * @throws InvalidQueryException
     */
    private function assertRawPagingIsWithinLimits(array $body): void
    {
        $position = static function (mixed $value, string $name): int {
            if ($value !== null && (!\is_int($value) || $value < 0)) {
                throw new InvalidQueryException(sprintf('"%s" is a whole number of rows and cannot be %s.', $name, get_debug_type($value) === 'int' ? 'negative' : 'a '.get_debug_type($value)));
            }

            return $value ?? -1;
        };

        $size = $position($body['size'] ?? null, 'size');
        $from = $position($body['from'] ?? null, 'from');

        if ($size === -1) {
            $size = 10; // Elasticsearch's own default
        }

        if ($size > $this->maxLimit) {
            throw new InvalidQueryException(sprintf('A request for %d rows is larger than reader.max_limit (%d). Raise the setting, or ask for fewer.', $size, $this->maxLimit));
        }

        if (isset($body['search_after'])) {
            if ($from > 0) {
                throw new InvalidQueryException('A body cannot carry both "from" and "search_after": one counts rows from the beginning, the other continues from a position. Elasticsearch refuses the pair, and a cursor has no depth limit anyway.');
            }

            return; // a cursor is not bounded by the window
        }

        $depth = max($from, 0) + $size;

        if ($depth > $this->maxResultWindow) {
            throw new InvalidQueryException(sprintf('from %d with size %d reaches row %d, past reader.max_result_window (%d). Raise it here and index.max_result_window on the cluster to match, or page with search_after, which has no such ceiling.', max($from, 0), $size, $depth, $this->maxResultWindow));
        }
    }

    /**
     * Which aggregations may be asked for, at any depth — a list of what is allowed,
     * not of what is known to be dangerous.
     *
     * The difference is the whole point. A deny-list was written first, naming `global`;
     * then `significant_terms` turned out to compare against the whole index as its
     * background, and `significant_text` after it, and `children` reaches into another
     * document scope. Each was a true finding, and the next one would have been too,
     * because "everything except the escapes we have thought of" is not a boundary. An
     * aggregation not on this list is refused by name, and adding one is a decision
     * someone makes deliberately, having asked what it counts.
     *
     * What is here answers only within the query: bucketing by a field, by a range, by
     * time; and the metrics over those buckets.
     */
    private const ALLOWED_AGGREGATIONS = [
        // buckets
        'terms', 'multi_terms', 'rare_terms', 'range', 'date_range', 'ip_range',
        'histogram', 'date_histogram', 'auto_date_histogram', 'variable_width_histogram',
        'filter', 'filters', 'missing', 'nested', 'reverse_nested', 'composite', 'sampler',
        'diversified_sampler', 'adjacency_matrix',
        // metrics
        'avg', 'min', 'max', 'sum', 'stats', 'extended_stats', 'value_count', 'cardinality',
        'percentiles', 'percentile_ranks', 'median_absolute_deviation', 'top_hits',
        'top_metrics', 'scripted_metric', 'string_stats', 'boxplot', 'geo_bounds',
        'geo_centroid', 'weighted_avg',
        // pipeline: they read other aggregations, not the index
        'avg_bucket', 'min_bucket', 'max_bucket', 'sum_bucket', 'stats_bucket',
        'extended_stats_bucket', 'percentiles_bucket', 'cumulative_sum', 'derivative',
        'moving_fn', 'moving_percentiles', 'serial_diff', 'bucket_script', 'bucket_selector',
        'bucket_sort', 'bucket_count_ks_test', 'normalize', 'inference',
    ];

    /**
     * Keys an aggregation node may carry beside the aggregation itself.
     */
    private const AGGREGATION_KEYWORDS = ['aggs', 'aggregations', 'meta'];

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
            $here = $path.'.'.(string) $name;

            // A shape this cannot read is refused rather than waved past. Skipping
            // everything that was not an array let the same tree through when it was
            // built from stdClass — which json_encode turns into exactly the JSON
            // Elasticsearch wants, `global` aggregations and all.
            if (!\is_array($aggregation)) {
                throw new InvalidQueryException(sprintf('raw() cannot vouch for the aggregation at "%s", which is %s rather than an array: the boundary is checked by reading it, so what cannot be read is refused. Build the body with arrays, keeping new \stdClass() for the empty objects Elasticsearch expects.', $here, get_debug_type($aggregation)));
            }

            foreach (array_keys($aggregation) as $key) {
                $key = (string) $key;

                if (\in_array($key, self::AGGREGATION_KEYWORDS, true)) {
                    continue;
                }

                if (!\in_array($key, self::ALLOWED_AGGREGATIONS, true)) {
                    throw new InvalidQueryException(sprintf('The aggregation at "%s" is a "%s", which raw() cannot vouch for: what it counts is not necessarily what the query allows — "global" ignores the query outright, "significant_terms" and "significant_text" compare against the whole index as their background, and "children" moves to another document scope. Aggregate with one of: %s.', $here, $key, implode(', ', self::ALLOWED_AGGREGATIONS)));
                }
            }

            foreach (['aggs', 'aggregations'] as $key) {
                if (!\array_key_exists($key, $aggregation)) {
                    continue;
                }

                if (!\is_array($aggregation[$key])) {
                    throw new InvalidQueryException(sprintf('raw() cannot vouch for "%s.%s" given as %s: sub-aggregations have to be arrays for the boundary to be checked at all.', $here, $key, get_debug_type($aggregation[$key])));
                }

                self::refuseGlobalAggregations($aggregation[$key], $here.'.'.$key);
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
        $cursor = $query->searchAfter;

        foreach ($this->extensions as $extension) {
            $query = $extension->extend($query);
        }

        // Narrowing a query abandons its cursor, because a cursor points into the result
        // set that produced it. An extension is the one case where that does not apply:
        // it runs identically on every page, so the narrowed query *is* the one the
        // cursor came from. Without this, an application with a visibility extension
        // would find every "next page" quietly returning the first — and only that
        // application, which is the worst way to find out.
        return $cursor !== null && !$query->usesCursor() && !$query->matchesNothing()
            ? $query->after($cursor)
            : $query;
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
