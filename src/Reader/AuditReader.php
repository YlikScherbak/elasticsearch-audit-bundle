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
        $query = $this->extend($query)->page(1, $batchSize);
        $this->assertWithinLimits($query);

        $index = $this->indexFor($query);

        // A point in time freezes what the export sees: records written while it runs do
        // not appear, and no record appears twice because a segment merged underneath.
        // Closed in finally, which a generator runs when the consumer stops early too.
        $pit = $consistent ? $this->gateway->openPointInTime($index, $this->pointInTimeKeepAlive) : null;

        try {
            while (true) {
                $response = $pit === null
                    ? $this->gateway->search($index, $this->queryBuilder->build($query))
                    : $this->gateway->searchPointInTime($pit, $this->pointInTimeKeepAlive, $this->queryBuilder->build($query, pointInTime: true));

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
                yield from $this->decorate(array_map(AuditEntry::fromHit(...), $hits));

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
