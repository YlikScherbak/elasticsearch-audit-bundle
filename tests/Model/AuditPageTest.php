<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditPage;
use Borsche\ElasticsearchAuditBundle\Model\Cursor;
use PHPUnit\Framework\TestCase;

/**
 * What a screen needs from a page besides its rows: whether to draw a "next", how far
 * the page numbers reach, and the cursor to carry on with.
 */
final class AuditPageTest extends TestCase
{
    public function testAPageInTheMiddleHasMore(): void
    {
        $page = new AuditPage(self::entries(20), total: 137, page: 2, limit: 20);

        self::assertTrue($page->hasMore());
        self::assertSame(7, $page->totalPages());
    }

    public function testTheLastPageDoesNot(): void
    {
        // 137 rows, twenty at a time: the seventh page holds the remaining seventeen.
        $page = new AuditPage(self::entries(17), total: 137, page: 7, limit: 20);

        self::assertFalse($page->hasMore());
        self::assertNull($page->nextCursor(), 'nothing follows, so there is nothing to continue from');
        self::assertNull($page->nextCursorToken());
    }

    public function testAFullLastPageDoesNotEither(): void
    {
        // The count alone would say "maybe more"; the total says otherwise.
        $page = new AuditPage(self::entries(20), total: 140, page: 7, limit: 20);

        self::assertFalse($page->hasMore());
    }

    public function testAnEmptyPageHasNothingAfterIt(): void
    {
        $page = new AuditPage([], total: 0, page: 1, limit: 20);

        self::assertTrue($page->isEmpty());
        self::assertFalse($page->hasMore());
        self::assertNull($page->nextCursor());
        self::assertSame(0, $page->maxReachablePage());
    }

    public function testAFullCursorPageMayHaveMore(): void
    {
        // A cursor knows nothing of totals: a full page is the only sign of more to come.
        $page = new AuditPage(self::entries(20), total: 137, page: 1, limit: 20, usesCursor: true);

        self::assertTrue($page->hasMore());
        self::assertSame(['2026-08-30 10:00:19', 'entry-19'], $page->nextCursor());
    }

    public function testAShortCursorPageIsTheEnd(): void
    {
        // The total is large and irrelevant: this batch came back short, so it is the last.
        $page = new AuditPage(self::entries(3), total: 137, page: 1, limit: 20, usesCursor: true);

        self::assertFalse($page->hasMore());
        self::assertNull($page->nextCursorToken(), 'no "load more" that leads nowhere');
    }

    public function testHowFarPageNumbersReach(): void
    {
        // A million rows, but only the first fifty thousand are reachable by page number.
        $page = new AuditPage(self::entries(10_000), total: 1_000_000, page: 1, limit: 10_000, maxResultWindow: 50_000);

        self::assertSame(100, $page->totalPages(), 'pages there are');
        self::assertSame(5, $page->maxReachablePage(), 'pages you can go to');
    }

    public function testAWindowWiderThanTheResultChangesNothing(): void
    {
        $page = new AuditPage(self::entries(20), total: 137, page: 1, limit: 20, maxResultWindow: 50_000);

        self::assertSame(7, $page->maxReachablePage());
    }

    public function testAPageOfNothingIsNotAPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A page holds at least one entry, 0 given as the limit.');

        new AuditPage([], total: 0, page: 1, limit: 0);
    }

    public function testALimitWiderThanTheWindowReachesNoPageAtAll(): void
    {
        // from + size would pass the window on the very first page, and the reader refuses
        // it: honest is zero reachable pages, not one that cannot be asked for.
        $page = new AuditPage(self::entries(10), total: 40, page: 1, limit: 10_000, maxResultWindow: 5_000);

        self::assertSame(1, $page->totalPages());
        self::assertSame(0, $page->maxReachablePage());
    }

    public function testATotalThatShrankUnderneathDoesNotInventANextPage(): void
    {
        // Records were deleted between two requests: page 3 of what is now 25 rows is past
        // the end. The arithmetic says so rather than offering a page that is not there.
        $page = new AuditPage([], total: 25, page: 3, limit: 20);

        self::assertFalse($page->hasMore());
        self::assertNull($page->nextCursorToken());
    }

    public function testNothingMatchedAtAll(): void
    {
        $pagination = (new AuditPage([], total: 0, page: 1, limit: 20))->toArray()['pagination'];

        self::assertSame(0, $pagination['total']);
        self::assertSame(0, $pagination['totalPages']);
        self::assertSame(0, $pagination['maxReachablePage']);
        self::assertFalse($pagination['hasMore']);
        self::assertNull($pagination['nextCursor']);
    }

    public function testANumberedPageOfRecordsWithoutIdsStillSerialises(): void
    {
        // Two right decisions meeting badly. A cursor whose tuple carries null cannot be
        // continued — those records have no order to continue from — and the advice for
        // such an index is to page by number. But toArray() asks for a token whether or
        // not anybody wants one, so a perfectly readable numbered page blew up on the
        // way to JSON. "There is more, and no cursor for it" is the honest answer.
        $page = new AuditPage(self::entries(20), total: 137, page: 1, limit: 20, fetched: 20, cursor: ['2026-08-30 10:00:00', null, 'audit_log'], query: 'fingerprint');

        $pagination = $page->toArray()['pagination'];

        self::assertTrue($pagination['hasMore'], 'page 2 is there');
        self::assertNull($pagination['nextCursor'], 'it just cannot be reached by cursor');
    }

    public function testACursorPageOfRecordsWithoutIdsStillRefuses(): void
    {
        // The same tuple, read the other way: there is no page number to fall back to
        // from inside a cursor traversal, so the refusal has to stand rather than turn
        // into a quiet end of the list.
        $page = new AuditPage(self::entries(20), total: 137, page: 1, limit: 20, usesCursor: true, fetched: 20, cursor: ['2026-08-30 10:00:00', null, 'audit_log'], query: 'fingerprint');

        $this->expectException(InvalidQueryException::class);

        $page->toArray();
    }

    public function testTheCursorInTheArrayFormIsAToken(): void
    {
        $page = new AuditPage(self::entries(20), total: 137, page: 1, limit: 20, query: 'fingerprint');

        $pagination = $page->toArray()['pagination'];

        self::assertSame(
            ['currentPage', 'limit', 'total', 'totalPages', 'maxReachablePage', 'hasMore', 'nextCursor'],
            array_keys($pagination),
        );
        self::assertTrue($pagination['hasMore']);
        self::assertIsString($pagination['nextCursor']);
        self::assertSame(['2026-08-30 10:00:19', 'entry-19'], Cursor::decode($pagination['nextCursor']), 'what the client hands back is what the reader asked for');
    }

    /**
     * @return list<AuditEntry>
     */
    public function testAPageWithNoEntriesHasNoNextCursorToOffer(): void
    {
        // The last page of a search that matched nothing, and the one place where
        // "where does the next page start" has no entry to answer from at all. Reaching
        // into an empty list for it is how a reader ends up with a fatal error on the
        // emptiest possible result.
        $page = new AuditPage([], total: 0, page: 1, limit: 20, fetched: 0, query: 'fingerprint');

        self::assertSame(0, $page->count());
        self::assertNull($page->nextCursor());
        self::assertNull($page->nextCursorToken());
    }

    public function testAPageCountsTheEntriesItActuallyHolds(): void
    {
        // total is what the query matched; count() is what this page came back with.
        // Reading one for the other is how a "137 results" heading ends up over three
        // rows, so both are on the page and both are read.
        $page = new AuditPage(self::entries(7), total: 137, page: 1, limit: 20, fetched: 7, query: 'fingerprint');

        self::assertSame(7, $page->count());
        self::assertSame(137, $page->total);
    }

    public function testAnEntryWithNothingToSortByEndsTheCursorEvenWithMoreToCome(): void
    {
        // A page that has a next page, whose last entry has nothing to continue from —
        // a record written before audit records carried sort values. Handing out its
        // empty tuple would produce a token that silently restarts from the top, which
        // an operator reads as "the history repeats itself". Null is the honest answer:
        // there is no cursor from here, page by number instead.
        //
        // The page has to be one with more to come, or nextCursor() answers null before
        // it ever looks at the entry — which is how this went untested.
        $entries = self::entries(20);
        $entries[19] = new AuditEntry(
            id: 'entry-19',
            objectType: 'order',
            objectId: 19,
            event: 'update',
            loggedAt: new \DateTimeImmutable('2026-08-30 10:00:19', new \DateTimeZone('UTC')),
            actor: 'alice',
            sort: [],
        );

        $page = new AuditPage($entries, total: 137, page: 1, limit: 20, fetched: 20, query: 'fingerprint');

        self::assertTrue($page->hasMore(), 'the page this is about is one that has a next');
        self::assertNull($page->nextCursor());
        self::assertNull($page->nextCursorToken());
    }

    private static function entries(int $count): array
    {
        $entries = [];

        for ($i = 0; $i < $count; ++$i) {
            $at = sprintf('2026-08-30 10:00:%02d', $i % 60);
            $entries[] = new AuditEntry(
                id: 'entry-'.$i,
                objectType: 'order',
                objectId: $i,
                event: 'update',
                loggedAt: new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
                actor: 'alice',
                sort: [$at, 'entry-'.$i],
            );
        }

        return $entries;
    }
}
