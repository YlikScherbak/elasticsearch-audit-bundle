<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Reading;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditPage;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;

/**
 * Reading the history back.
 *
 * `AuditQuery` says what to look for, `AuditReader` answers with an `AuditPage`.
 * Every filter is an exact match on an indexed field, so the queries stay fast at
 * millions of records: a base field has its own method, an attribute goes through
 * `where()`.
 */
final class ReadingTheHistory
{
    public function __construct(private readonly AuditReader $reader)
    {
    }

    /**
     * One object's history, newest first — the panel on an order's detail page.
     */
    public function forOneOrder(int $orderId): AuditPage
    {
        return $this->reader->find(
            AuditQuery::for('order')
                ->withObjectId($orderId)
                ->page(1, 20),
        );
    }

    /**
     * The filters, together. `any()` reads across object types — every index the
     * configuration routes to, in one search, so a type that lives in its own index
     * is not left out.
     */
    public function whatThisPersonDidLastWeek(string $actor): AuditPage
    {
        return $this->reader->find(
            AuditQuery::any()
                ->withActors($actor)
                ->withEvents('update', 'remove')
                ->since(new \DateTimeImmutable('-7 days'))
                ->where('orderCountry', 'PL')          // an attribute your enricher added
                ->whereExists('reason')                 // has the field at all
                ->whereBetween('totalCents', 10_000, null)
                ->page(1, 50),
        );
    }

    /**
     * Paging by number, the familiar way — and the one that has a floor and a
     * ceiling. `reader.max_limit` bounds how large a page may be and
     * `reader.max_result_window` how deep `page × limit` may reach, because that is
     * Elasticsearch's own limit and asking past it is an error, not a slow answer.
     *
     * `$page->maxReachablePage()` is what a paginator should draw rather than
     * `totalPages()` when the two differ.
     */
    public function pageThrough(int $page): AuditPage
    {
        return $this->reader->find(AuditQuery::for('order')->page($page, 100));
    }

    /**
     * Paging by cursor, which is the one to use past the first few pages: it does
     * not skip records when new ones arrive while somebody reads, and it has no
     * depth limit.
     *
     * The token is opaque and safe to put in a URL or a JSON body. It is bound to
     * the query that produced it — hand it to a different filter set and the reader
     * refuses it rather than answering from somebody else's result set.
     */
    public function nextSlice(?string $token): AuditPage
    {
        $query = AuditQuery::for('order')->limit(100);

        return $this->reader->find($token === null ? $query : $query->afterToken($token));
    }

    /**
     * Everything matching, for an export. `iterate()` walks the whole result set in
     * batches and yields entries one by one; `consistent: true` reads through a
     * point in time, so a long export sees the index as it was when it started.
     *
     * @return \Generator<int, AuditEntry>
     */
    public function exportEverything(\DateTimeImmutable $since): \Generator
    {
        yield from $this->reader->iterate(
            AuditQuery::any()->since($since),
            batchSize: 1000,
            consistent: true,
        );
    }

    /**
     * What a page carries: the entries, an exact total, and whether there is more.
     *
     * @return array{lines: list<array<string, mixed>>, total: int, next: string|null}
     */
    public function asAnApiWouldReturnIt(int $orderId): array
    {
        $page = $this->forOneOrder($orderId);

        return [
            'lines' => array_map(static fn (AuditEntry $entry): array => $entry->toArray(), $page->entries),
            'total' => $page->total,
            'next' => $page->nextCursorToken(),
        ];
    }
}
