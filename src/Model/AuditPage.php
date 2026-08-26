<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * One page of history plus what is needed to render pagination or fetch the next
 * page: the total, the page/limit the query used, and a cursor for after().
 */
final class AuditPage
{
    /**
     * @param list<AuditEntry> $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $limit,
    ) {
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
     * Pass to AuditQuery::after() to get the entries following this page.
     * Null when the page is empty — there is nothing after nothing.
     *
     * @return list<mixed>|null
     */
    public function nextCursor(): ?array
    {
        $last = $this->entries[array_key_last($this->entries) ?? -1] ?? null;

        return $last === null || $last->sort === [] ? null : $last->sort;
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array{currentPage: int, limit: int, total: int, totalPages: int, nextCursor: list<mixed>|null}}
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
                'nextCursor' => $this->nextCursor(),
            ],
        ];
    }
}
