<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Model\Filter;
use PHPUnit\Framework\TestCase;

/**
 * What a QueryExtension does is almost always narrowing — "of what was asked for,
 * only what this viewer may see". with*() replaces, and a replacement that was meant
 * as a boundary silently *widens* the result instead: the one mistake a visibility
 * extension must not be able to make. narrow*() intersects, and an intersection
 * that comes up empty is an answer ("nothing"), not a query.
 */
final class QueryNarrowingTest extends TestCase
{
    public function testNarrowingIntersectsInsteadOfReplacing(): void
    {
        // The client asked for 1, 2, 3; the viewer may see 2, 3, 4. with*() here would
        // answer with 4 as well — records the client never asked about.
        $query = AuditQuery::for('order')->withObjectIds(1, 2, 3)->narrowObjectIds(2, 3, 4);

        self::assertSame([2, 3], $query->objectIds);
        self::assertFalse($query->matchesNothing());
    }

    public function testTheFirstNarrowingIsSimplyTheBoundary(): void
    {
        // Nothing was asked for specifically, so everything was on the table; the
        // narrow is the first fence around it.
        self::assertSame([7], AuditQuery::for('order')->narrowObjectIds(7)->objectIds);
        self::assertSame(['u1'], AuditQuery::for('order')->narrowActors('u1')->actors);
    }

    public function testAnEmptyIntersectionIsKnownEmptiness(): void
    {
        $query = AuditQuery::for('order')->withObjectIds(1, 2)->narrowObjectIds(3, 4);

        self::assertTrue($query->matchesNothing(), 'the client asked for records the viewer may not see: the honest answer is an empty page');
    }

    public function testActorsNarrowTheSameWay(): void
    {
        $query = AuditQuery::for('order')->withActors('u1', 'u2')->narrowActors('u2', 'u3');

        self::assertSame(['u2'], $query->actors);
    }

    public function testNarrowInIntersectsAValueList(): void
    {
        $query = AuditQuery::for('order')->whereIn('orderCountry', ['UA', 'PL', 'DE'])->narrowIn('orderCountry', ['PL', 'DE', 'CZ']);

        self::assertEquals(Filter::in(['PL', 'DE']), $query->filters['orderCountry']);
    }

    public function testNarrowInAgainstASingleValueKeepsOrEmpties(): void
    {
        $kept = AuditQuery::for('order')->where('orderCountry', 'UA')->narrowIn('orderCountry', ['UA', 'PL']);
        $emptied = AuditQuery::for('order')->where('orderCountry', 'FR')->narrowIn('orderCountry', ['UA', 'PL']);

        self::assertEquals(Filter::is('UA'), $kept->filters['orderCountry']);
        self::assertFalse($kept->matchesNothing());
        self::assertTrue($emptied->matchesNothing());
    }

    public function testNarrowInOverExistsBecomesTheList(): void
    {
        // "Has the field, and it is one of these" is just "one of these": a document
        // with the value has the field.
        $query = AuditQuery::for('order')->whereExists('orderCountry')->narrowIn('orderCountry', ['UA']);

        self::assertEquals(Filter::in(['UA']), $query->filters['orderCountry']);
    }

    public function testNarrowInOverMissingIsNothing(): void
    {
        // No value and one of these values at once: no document satisfies both.
        $query = AuditQuery::for('order')->whereNotExists('orderCountry')->narrowIn('orderCountry', ['UA']);

        self::assertTrue($query->matchesNothing());
    }

    public function testNarrowInCannotNarrowARange(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('filtered by a range');

        AuditQuery::for('order')->whereBetween('total', 100, 500)->narrowIn('total', [200]);
    }

    public function testNothingIsSticky(): void
    {
        // Once an extension has said "this viewer sees none of it", no later filter
        // may widen the answer back open.
        $nothing = AuditQuery::for('order')->matchNothing();

        self::assertTrue($nothing->matchesNothing());
        self::assertTrue($nothing->withObjectIds(5)->matchesNothing());
        self::assertTrue($nothing->narrowObjectIds(5)->matchesNothing());
        self::assertTrue($nothing->page(2, 50)->matchesNothing(), 'and paging is not a filter at all');
    }

    public function testNumericStringsAndIntegersIntersectLikeElasticsearchWouldMatchThem(): void
    {
        // The HTTP layer hands ids over as strings; Elasticsearch matches them against
        // numeric fields all the same, and the intersection follows that.
        $query = AuditQuery::for('order')->withObjectIds('2', '3')->narrowObjectIds(3, 4);

        self::assertSame(['3'], $query->objectIds);
    }

    public function testABetweenFilterNeedsAtLeastOneBound(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('at least one bound');

        AuditQuery::for('order')->whereBetween('total', null, null);
    }

    public function testNarrowingRefusesAnEmptyList(): void
    {
        // An empty list is not "nothing may be seen" — matchNothing() says that; it is
        // a mistake, the same one the with*() family refuses.
        $this->expectException(InvalidQueryException::class);

        AuditQuery::for('order')->narrowObjectIds();
    }
}
