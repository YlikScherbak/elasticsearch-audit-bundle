<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a query refuses to be built out of, and where the boundaries of "allowed" are.
 *
 * Every method here is one an application calls with something a user typed — an
 * attribute name from a query string, a page number from a link, a limit from a form.
 * The refusals were written for that, and then tested through whichever of the five
 * doors happened to be convenient, which left four of them able to skip the check
 * entirely without a single test noticing.
 */
final class QueryRefusalsTest extends TestCase
{
    /**
     * Each door into the attribute-name check, with something the check exists to
     * refuse. An attribute name reaches Elasticsearch as a field path; the ones it must
     * not reach it as are the whole reason this is not just passed through.
     *
     * @return iterable<string, array{callable(AuditQuery, string): AuditQuery}>
     */
    public static function doorsTakingAnAttributeName(): iterable
    {
        yield 'where' => [static fn (AuditQuery $q, string $name): AuditQuery => $q->where($name, 'x')];
        yield 'whereIn' => [static fn (AuditQuery $q, string $name): AuditQuery => $q->whereIn($name, ['x'])];
        yield 'whereExists' => [static fn (AuditQuery $q, string $name): AuditQuery => $q->whereExists($name)];
        yield 'whereNotExists' => [static fn (AuditQuery $q, string $name): AuditQuery => $q->whereNotExists($name)];
        yield 'whereBetween' => [static fn (AuditQuery $q, string $name): AuditQuery => $q->whereBetween($name, 1, 2)];
    }

    /**
     * @param callable(AuditQuery, string): AuditQuery $door
     */
    #[DataProvider('doorsTakingAnAttributeName')]
    public function testEveryDoorChecksTheAttributeName(callable $door): void
    {
        $this->expectException(InvalidQueryException::class);

        $door(AuditQuery::any(), '');
    }

    /**
     * @param callable(AuditQuery, string): AuditQuery $door
     */
    #[DataProvider('doorsTakingAnAttributeName')]
    public function testEveryDoorTakesANameThatIsFine(callable $door): void
    {
        // The other half: a check that refuses everything would pass the test above and
        // make the bundle useless.
        $query = $door(AuditQuery::any(), 'salesChannel');

        self::assertNotSame([], $query->filters);
    }

    public function testTheTwoDatesMayBeTheSameInstant(): void
    {
        // A range of one day, asked for as the same timestamp twice, is a question with
        // an answer. Refusing it would be the query saying "nothing happened" about a
        // day that has not been looked at.
        $at = new \DateTimeImmutable('2026-09-15 12:00:00');
        $query = AuditQuery::any()->between($at, $at);

        self::assertEquals($at, $query->from);
        self::assertEquals($at, $query->to);
    }

    public function testDatesTheWrongWayRoundAreRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('after the "to" date');

        AuditQuery::any()->between(new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-09-14'));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function pages(): iterable
    {
        yield 'the first page' => [1, 20, true];
        yield 'page zero' => [0, 20, false];
        yield 'a page before the first' => [-1, 20, false];
        yield 'the smallest page that holds anything' => [1, 1, true];
        yield 'a page holding nothing' => [1, 0, false];
        yield 'a negative page size' => [1, -5, false];
    }

    #[DataProvider('pages')]
    public function testWhereTheEdgesOfAPageAre(int $page, int $limit, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(InvalidQueryException::class);
        }

        $query = AuditQuery::any()->page($page, $limit);

        self::assertSame($page, $query->page);
        self::assertSame($limit, $query->limit);
    }

    public function testALimitOnItsOwnHasTheSameFloor(): void
    {
        self::assertSame(1, AuditQuery::any()->limit(1)->limit);

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('at least 1');

        AuditQuery::any()->limit(0);
    }

    public function testAnOptionIsKeptAndReplacedRatherThanAccumulated(): void
    {
        $query = AuditQuery::any()->withOption('tenant', 'acme')->withOption('scope', 'all');

        self::assertSame('acme', $query->option('tenant'));
        self::assertSame('all', $query->option('scope'));

        $changed = $query->withOption('tenant', 'globex');

        self::assertSame('globex', $changed->option('tenant'));
        self::assertSame('all', $changed->option('scope'), 'setting one option dropped another');
        self::assertSame('acme', $query->option('tenant'), 'and the query it was built from changed underneath');
    }

    public function testAnOptionIsSomethingThatReadsTheSameWayEveryTime(): void
    {
        // Options travel in the fingerprint a cursor carries, so an option whose text
        // depends on an object's identity would make a token stop matching its own
        // query between two requests.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('stdClass');

        AuditQuery::any()->withOption('tenant', new \stdClass());
    }

    public function testAnOptionMayNestAsDeepAsItIsAllowedToAndNoDeeper(): void
    {
        // The wrapper counts as a level, so seven arrays inside it is the deepest that
        // is read and eight is the first that is refused — a limit is only a limit if
        // something stands on both sides of it.
        $atTheLimit = AuditQuery::any()->withOption('scope', self::nested(7));

        self::assertIsArray($atTheLimit->option('scope'));

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('nested no deeper than 8 levels');

        AuditQuery::any()->withOption('scope', self::nested(8));
    }

    public function testACursorWithNothingToContinueFromIsRefused(): void
    {
        // A null in the sort tuple means the record had no value for a field the query
        // sorts by — records written before audit records carried ids. search_after
        // cannot tell two of those apart, so it steps over one, and a page quietly
        // missing a record is not a page an audit trail may hand out.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('no order Elasticsearch can continue from');

        AuditQuery::any()->after(['2026-08-30 10:00:00', null]);
    }

    public function testACursorIsKeptAsAListWhateverKeysItArrivedWith(): void
    {
        $query = AuditQuery::any()->after([2 => '2026-08-30 10:00:00', 5 => 'entry-19']);

        self::assertSame(['2026-08-30 10:00:00', 'entry-19'], $query->searchAfter);
    }

    public function testABooleanIsNotTheStringThatLooksLikeIt(): void
    {
        // Narrowing compares values the way a visibility boundary has to: PHP reads
        // true == "1" as agreement, Elasticsearch keeps a boolean field and a numeric
        // one apart, and a narrowing that guessed wrongly would widen what a viewer can
        // see rather than narrow it.
        $onTrue = AuditQuery::any()->where('flagged', true);

        self::assertTrue($onTrue->narrowIn('flagged', ['1'])->nothing, 'a string stood in for a boolean');
        self::assertFalse($onTrue->narrowIn('flagged', [true])->nothing);

        $onOne = AuditQuery::any()->where('flagged', 1);

        self::assertTrue($onOne->narrowIn('flagged', [true])->nothing, 'a boolean stood in for a number');
        self::assertFalse($onOne->narrowIn('flagged', [1])->nothing);
    }

    public function testNarrowingChecksTheAttributeNameToo(): void
    {
        // The sixth door, and the one that matters most: narrowIn() is what a visibility
        // extension calls with something derived from a request. It reached the same
        // check as the others and nothing said so.
        $this->expectException(InvalidQueryException::class);

        AuditQuery::any()->narrowIn('', ['x']);
    }

    public function testNarrowingLeavesAListRatherThanTheHolesItFilteredOut(): void
    {
        // Narrowing keeps the values that survive, and "the ones that survive" is what
        // array_filter leaves behind — at the keys they had. A JSON object where a terms
        // query expects an array is not a terms query, so the page comes back empty:
        // the one answer an audit trail must never give by accident.
        $narrowed = AuditQuery::any()
            ->whereIn('channel', ['web', 'phone', 'shop'])
            ->narrowIn('channel', ['web', 'shop']);

        self::assertSame(['web', 'shop'], $narrowed->filters['channel']->values);
    }

    public function testAListOfValuesIsAListWhateverKeysItArrivedWith(): void
    {
        // The same thing one step earlier: an application that built its list with
        // array_filter hands over holes, and whereIn() is where they are closed.
        $query = AuditQuery::any()->whereIn('channel', [3 => 'web', 7 => 'shop']);

        self::assertSame(['web', 'shop'], $query->filters['channel']->values);
    }

    public function testAPageAsksForTwentyEntriesUnlessItSaysOtherwise(): void
    {
        // A default that is part of the API: a caller paging with page(2) and nothing
        // else is promised the same page size it got from page(1).
        self::assertSame(20, AuditQuery::any()->page(2)->limit);
    }

    /**
     * @return array<string, mixed>
     */
    private static function nested(int $depth): array
    {
        $value = 'leaf';

        for ($i = 0; $i < $depth; ++$i) {
            $value = [$value];
        }

        return ['at' => $value];
    }
}
