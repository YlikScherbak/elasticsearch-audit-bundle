<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
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
