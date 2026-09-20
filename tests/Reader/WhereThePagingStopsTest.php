<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

/**
 * Exactly where the reader's limits fall, on both sides of each one.
 *
 * ReaderLimitsTest says that the limits exist and that raising the settings raises
 * them. This one is about the boundary itself: a request for exactly max_limit rows is
 * allowed and one row more is refused, a body reaching exactly max_result_window is
 * allowed and one row past it is refused. A limit tested only from the far side is a
 * limit that can move by one without anything noticing — and the person it moves under
 * is whoever configured the setting to the number they actually need.
 *
 * The same goes for the numbers inside the refusals: "reaches row 11000" is what tells
 * an operator which setting to raise and by how much, so the arithmetic behind it is
 * asserted rather than the fact that some message was raised.
 */
final class WhereThePagingStopsTest extends TestCase
{
    /** @var list<string> a cursor of the width this query sorts by */
    private const CURSOR = ['2026-08-28 10:00:00', 'audit_log', '01a03df1-0000-0000-0000-000000000000'];

    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->gateway->respondToSearch = static fn (): array => ['hits' => ['total' => ['value' => 0], 'hits' => []]];
    }

    public function testASizeOfExactlyMaxLimitIsAllowedAndOneMoreIsNot(): void
    {
        $reader = $this->reader(maxLimit: 100, maxWindow: 10_000);

        $reader->raw(AuditQuery::for('order'), ['size' => 100]);

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('A request for 101 rows is larger than reader.max_limit (100)');

        $reader->raw(AuditQuery::for('order'), ['size' => 101]);
    }

    public function testABodyWithNoSizeAsksForTheClustersOwnTen(): void
    {
        // Elasticsearch answers ten when a body says nothing, so that is the number the
        // depth has to be measured with. Read as any other number, a body that sits
        // exactly on the window is refused for rows it never asked for, or one that
        // overruns it is let through to be refused by the cluster instead.
        $reader = $this->reader(maxLimit: 100, maxWindow: 100);

        $reader->raw(AuditQuery::for('order'), ['from' => 90]);

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('from 91 with size 10 reaches row 101');

        $reader->raw(AuditQuery::for('order'), ['from' => 91]);
    }

    public function testABodyWithNoFromStartsAtRowZero(): void
    {
        // "from" absent is row zero, not row one and not row minus one: both of those
        // move the window by a row, in opposite directions, for every body that leaves
        // it out — which is most of them.
        $reader = $this->reader(maxLimit: 100, maxWindow: 100);

        $reader->raw(AuditQuery::for('order'), ['size' => 100]);

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('from 1 with size 100 reaches row 101');

        $reader->raw(AuditQuery::for('order'), ['size' => 100, 'from' => 1]);
    }

    public function testABodyWithNoFromIsMeasuredFromRowZeroAndSaysSo(): void
    {
        // The absent "from" is carried as -1 until the depth is worked out, and turned
        // into 0 there. A body large enough to overrun the window on its size alone is
        // where that matters: read as -1, the depth comes out a row short and the body
        // is let through to be refused by the cluster instead — and the refusal, when it
        // does come, names the row it would have reached, so the number an operator puts
        // in index.max_result_window comes from this arithmetic too.
        $reader = $this->reader(maxLimit: 200, maxWindow: 100);

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('from 0 with size 101 reaches row 101, past reader.max_result_window (100)');

        $reader->raw(AuditQuery::for('order'), ['size' => 101]);
    }

    public function testACursorInABodyIsNotBoundedByTheWindowButStillRefusesAnOffset(): void
    {
        // search_after has no depth: it continues from a position rather than counting
        // rows from the beginning, so the window does not apply to it. "from: 0" is not
        // an offset, and a body carrying both it and a cursor is the ordinary shape of a
        // first continuation — refusing that pair would refuse the whole road.
        $reader = $this->reader(maxLimit: 100, maxWindow: 10);

        $reader->raw(AuditQuery::for('order'), ['size' => 100, 'from' => 0, 'search_after' => ['x']]);

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('cannot carry both "from" and "search_after"');

        $reader->raw(AuditQuery::for('order'), ['size' => 100, 'from' => 1, 'search_after' => ['x']]);
    }

    public function testAPositionThatIsNotAWholeNumberOfRowsSaysWhichKindOfWrongItIs(): void
    {
        // Two different mistakes and two different sentences: a negative number is a
        // number, and being told "cannot be a int" for it would send the reader looking
        // for a type error that is not there.
        $reader = $this->reader();

        try {
            $reader->raw(AuditQuery::for('order'), ['size' => -5]);
            self::fail('expected InvalidQueryException');
        } catch (InvalidQueryException $e) {
            self::assertSame('"size" is a whole number of rows and cannot be negative.', $e->getMessage());
        }

        try {
            $reader->raw(AuditQuery::for('order'), ['from' => '10']);
            self::fail('expected InvalidQueryException');
        } catch (InvalidQueryException $e) {
            self::assertSame('"from" is a whole number of rows and cannot be a string.', $e->getMessage());
        }
    }

    public function testAPageRefusalNamesTheRowItWouldHaveReached(): void
    {
        // The row is page times limit, and it is the number the operator sets
        // index.max_result_window to. Any other arithmetic there sends them to a setting
        // that still will not fit the page they asked for.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('Page 11 with 1000 per page reaches row 11000, past reader.max_result_window (10000)');

        $this->reader()->find(AuditQuery::for('order')->page(11, 1000));
    }

    public function testAPageContinuedByACursorIsNotBoundedByTheWindowEither(): void
    {
        // The same fact as the raw body's, on the road applications actually take. A
        // cursor query carries page 1, so the window only catches it when a page is
        // larger than the window — which is exactly how a deployment that raised
        // max_limit for an export and left max_result_window alone is configured.
        $reader = $this->reader(maxLimit: 5_000, maxWindow: 1_000);

        $page = $reader->find(AuditQuery::for('order')->page(1, 5_000)->after(self::CURSOR));

        self::assertSame([], $page->entries, 'the cursor was not refused by a window it does not reach into');
    }

    public function testAQueryAnExtensionNarrowedToNothingIsAnAnswerNotAMismatchedCursor(): void
    {
        // A token carries the query it was issued for, and continuing it against a
        // different query is refused — that is what keeps a cursor from paging through
        // somebody else's result set. An extension that narrows to nothing produces a
        // different query by definition, and the empty answer has to come before that
        // comparison: there is no result set left to be in the wrong one of.
        // A real token, from a real page: the provenance it carries is the thing under
        // test, and a hand-made one would carry whatever this test assumed.
        $this->gateway->respondToSearch = static fn (): array => ['hits' => ['total' => ['value' => 3], 'hits' => [
            ['_id' => 'a', 'sort' => self::CURSOR, '_source' => ['objectType' => 'order', 'objectId' => 1, 'event' => 'update', 'loggedAt' => '2026-08-28 10:00:00', 'source' => 'a', 'changes' => []]],
        ]]];

        $issued = $this->reader()->find(AuditQuery::for('order')->page(1, 1));
        $token = $issued->nextCursorToken();

        self::assertIsString($token, 'the premise: this page hands out a token');

        $narrowed = $this->reader(extensions: [new class implements QueryExtensionInterface {
            public function extend(AuditQuery $query): AuditQuery
            {
                return $query->matchNothing();
            }
        }]);

        $page = $narrowed->find(AuditQuery::for('order')->afterToken($token));

        self::assertSame([], $page->entries);
        self::assertFalse($page->hasMore());
    }

    /**
     * @param iterable<QueryExtensionInterface> $extensions
     */
    private function reader(int $maxLimit = 1_000, int $maxWindow = 10_000, iterable $extensions = []): AuditReader
    {
        return new AuditReader(
            $this->gateway,
            new IndexResolver('audit_log'),
            extensions: $extensions,
            maxLimit: $maxLimit,
            maxResultWindow: $maxWindow,
        );
    }
}
