<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditQueryTest extends TestCase
{
    public function testWithersReturnCopies(): void
    {
        $base = AuditQuery::for('order');
        $filtered = $base->withObjectIds(1, 2)->withEvents('update')->where('salesType', 3)->withOption('country', 'UA');

        self::assertSame([], $base->objectIds);
        self::assertSame([1, 2], $filtered->objectIds);
        self::assertSame(['update'], $filtered->events);
        self::assertSame(['salesType' => 3], $filtered->filters);
        self::assertSame('UA', $filtered->option('country'));
        self::assertTrue($filtered->hasOption('country'));
        self::assertNull($filtered->option('missing'));
    }

    public function testARepeatedAttributeOrOptionReplacesTheEarlierValue(): void
    {
        $query = AuditQuery::for('order')
            ->where('salesType', 3)->where('salesType', 5)
            ->whereIn('country', ['UA'])->whereIn('country', ['PL', 'DE'])
            ->withOption('mine', false)->withOption('mine', true);

        self::assertSame(['salesType' => 5, 'country' => ['PL', 'DE']], $query->filters);
        self::assertTrue($query->option('mine'));
    }

    public function testPagingDefaultsAndOffset(): void
    {
        $query = AuditQuery::for('order');

        self::assertSame(1, $query->page);
        self::assertSame(20, $query->limit);
        self::assertSame(0, $query->offset());
        self::assertSame(40, $query->page(3, 20)->offset());
        self::assertFalse($query->usesCursor());
    }

    public function testACursorReplacesThePageAndAPageResetsTheCursor(): void
    {
        $withCursor = AuditQuery::for('order')->page(5)->after(['2026-08-26 10:00:00', 17]);

        self::assertTrue($withCursor->usesCursor());
        self::assertSame(1, $withCursor->page);
        self::assertSame(['2026-08-26 10:00:00', 17], $withCursor->searchAfter);

        self::assertFalse($withCursor->page(2)->usesCursor());
        self::assertTrue($withCursor->limit(50)->usesCursor() === false, 'limit() goes through page() and resets the cursor');
        self::assertTrue($withCursor->withEvents('x')->usesCursor(), 'other withers keep the cursor');
    }

    public function testDateRangeCanBeOpenOnEitherSide(): void
    {
        $from = new \DateTimeImmutable('2026-01-01');
        $query = AuditQuery::for('order')->since($from);

        self::assertEquals($from, $query->from);
        self::assertNull($query->to);
        self::assertNull($query->between(null, null)->from);
    }

    /**
     * @param callable(AuditQuery): AuditQuery $mutation
     */
    #[DataProvider('invalid')]
    public function testInvalidQueriesAreRejectedEarly(callable $mutation, string $message): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage($message);

        $mutation(AuditQuery::for('order'));
    }

    /**
     * @return iterable<string, array{callable(AuditQuery): AuditQuery, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty type' => [static fn (AuditQuery $q) => AuditQuery::for(''), 'cannot be empty'];
        yield 'empty ids' => [static fn (AuditQuery $q) => $q->withObjectIds(), 'object ids cannot be empty'];
        yield 'empty in-list' => [static fn (AuditQuery $q) => $q->whereIn('salesType', []), 'values for "salesType"'];
        yield 'base field as attribute' => [static fn (AuditQuery $q) => $q->where('source', 'x'), 'dedicated method'];
        yield 'from after to' => [static fn (AuditQuery $q) => $q->between(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-01')), 'after the "to"'];
        yield 'page zero' => [static fn (AuditQuery $q) => $q->page(0), 'starts at 1'];
        yield 'limit too high' => [static fn (AuditQuery $q) => $q->page(1, 1001), 'between 1 and 1000'];
        yield 'too deep' => [static fn (AuditQuery $q) => $q->page(11, 1000), 'Use after()'];
        yield 'empty cursor' => [static fn (AuditQuery $q) => $q->after([]), 'cursor is empty'];
    }
}
