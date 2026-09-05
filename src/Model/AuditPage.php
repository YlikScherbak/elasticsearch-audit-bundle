<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * One page of history plus what is needed to render pagination or fetch the next
 * page: the total, the page/limit the query used, whether anything follows, how far
 * page numbers reach, and a cursor for after().
 */
final class AuditPage
{
    /**
     * @param list<AuditEntry> $entries
     * @param bool             $usesCursor whether the query that produced this page paged with after() rather than page()
     * @param int              $maxResultWindow how deep page numbers may reach — reader.max_result_window
     * @param int|null         $fetched    how many hits Elasticsearch returned for this page; null means "as many as $entries".
     *                                     The two differ when a decorator hid some — what is shown is the decorator's
     *                                     call, whether more follows is the cluster's
     * @param list<mixed>|null $cursor     the sort values of the last hit Elasticsearch returned; null means "the last entry's"
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $limit,
        public readonly bool $usesCursor = false,
        public readonly int $maxResultWindow = AuditQuery::DEFAULT_MAX_WINDOW,
        private readonly ?int $fetched = null,
        private readonly ?array $cursor = null,
        private readonly ?string $query = null,
    ) {
        // The reader never builds one this way — AuditQuery::page() refuses a limit below 1 —
        // but a page assembled by hand (a test, a cache, a decorator that repacks a result)
        // would divide by it and blow up somewhere far from the mistake.
        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('A page holds at least one entry, %d given as the limit.', $limit));
        }
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function totalPages(): int
    {
        return (int) ceil($this->total / $this->limit);
    }

    /**
     * How many pages `page()` can actually reach. Past the window Elasticsearch answers
     * with an error rather than rows, so a screen has to tell "pages there are" from
     * "pages you can go to" — beyond it, page by cursor or narrow the query.
     */
    public function maxReachablePage(): int
    {
        return min($this->totalPages(), intdiv($this->maxResultWindow, $this->limit));
    }

    /**
     * Whether anything follows this page.
     *
     * With page numbers the answer is arithmetic. A cursor page knows nothing about how
     * far into the result set it is, so a full page means there may be more and a short
     * one means the cursor reached the end — one extra request at the end, and never a
     * "load more" that leads nowhere.
     */
    public function hasMore(): bool
    {
        $fetched = $this->fetched ?? $this->count();

        if ($this->usesCursor) {
            return $fetched === $this->limit;
        }

        return ($this->page - 1) * $this->limit + $fetched < $this->total;
    }

    /**
     * Pass to AuditQuery::after() to get the entries following this page.
     * Null when nothing follows — an empty page, or the last one.
     *
     * @return list<mixed>|null
     */
    public function nextCursor(): ?array
    {
        if (!$this->hasMore()) {
            return null;
        }

        if ($this->cursor !== null) {
            return $this->cursor === [] ? null : $this->cursor;
        }

        $last = $this->entries[array_key_last($this->entries) ?? -1] ?? null;

        return $last === null || $last->sort === [] ? null : $last->sort;
    }

    /**
     * The same cursor as one string, for a URL or a JSON response. Opaque on purpose:
     * a client hands it back as it received it (AuditQuery::afterToken()), which leaves
     * what is inside free to change.
     */
    public function nextCursorToken(): ?string
    {
        $cursor = $this->nextCursor();

        // The token carries which query produced it. Continuing it on another one is not
        // a mistake Elasticsearch can see — the sort tuple has the right shape, so it
        // answers with what follows that position in whatever set is being searched, and
        // everything before it is quietly missing.
        return $cursor === null ? null : Cursor::encode($cursor, $this->query);
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{currentPage: int, limit: int, total: int, totalPages: int, maxReachablePage: int, hasMore: bool, nextCursor: string|null}}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (AuditEntry $e) => $e->toArray(), $this->entries),
            'pagination' => [
                'currentPage' => $this->page,
                'limit' => $this->limit,
                'total' => $this->total,
                'totalPages' => $this->totalPages(),
                'maxReachablePage' => $this->maxReachablePage(),
                'hasMore' => $this->hasMore(),
                'nextCursor' => $this->nextCursorToken(),
            ],
        ];
    }
}
