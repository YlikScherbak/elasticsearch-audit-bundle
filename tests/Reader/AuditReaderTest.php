<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

final class AuditReaderTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testEntriesAreBuiltFromHitsAndPaginationFromTheTotal(): void
    {
        $this->gateway->respondToSearch = static fn () => [
            'hits' => ['total' => ['value' => 45], 'hits' => [
                ['_id' => 'a', 'sort' => ['2026-08-26 10:00:00', 3], '_source' => ['objectType' => 'order', 'objectId' => 42, 'event' => 'update', 'loggedAt' => '2026-08-26 10:00:00', 'source' => '7', 'changes' => ['status' => ['old' => 'a', 'new' => 'b']], 'salesType' => 3]],
            ]],
        ];

        $page = $this->reader()->find(AuditQuery::for('order')->page(2, 20));

        self::assertSame(45, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame(3, $page->totalPages());
        self::assertSame(['2026-08-26 10:00:00', 3], $page->nextCursor());

        $entry = $page->entries[0];
        self::assertSame('a', $entry->id);
        self::assertSame(42, $entry->objectId);
        self::assertSame('7', $entry->actor);
        self::assertSame('2026-08-26T10:00:00+00:00', $entry->loggedAt->format(\DATE_ATOM));
        self::assertSame(['salesType' => 3], $entry->attributes);
        self::assertSame(3, $entry->attribute('salesType'));
        self::assertSame(['status' => ['old' => 'a', 'new' => 'b']], $entry->changes);
    }

    public function testAQueryThatMatchesNothingNeverReachesTheCluster(): void
    {
        $page = $this->reader()->find(AuditQuery::for('order')->matchNothing()->page(2, 50));

        self::assertSame([], $this->gateway->searches, 'known emptiness costs no request');
        self::assertTrue($page->isEmpty());
        self::assertSame(0, $page->total);
        self::assertFalse($page->hasMore());
        self::assertNull($page->nextCursor(), 'and there is no "load more" pointing into the void');
        self::assertNull($page->nextCursorToken());
        self::assertSame(2, $page->page, 'the page still answers as the page that was asked for');
    }

    public function testOneExtensionsNothingSurvivesTheNextExtensionsFilter(): void
    {
        // The class of bug narrow*() exists for, at the boundary where it matters: A
        // said "this viewer sees none of these", B added its own filter after — and an
        // un-sticky nothing would have quietly reopened the visibility A closed.
        $a = new class implements QueryExtensionInterface {
            public function extend(AuditQuery $query): AuditQuery
            {
                return $query->narrowObjectIds(90, 91); // viewer's ids; disjoint from the client's
            }
        };
        $b = new class implements QueryExtensionInterface {
            public function extend(AuditQuery $query): AuditQuery
            {
                return $query->withEvents('update')->where('country', 'UA');
            }
        };

        $page = $this->reader(extensions: [$a, $b])->find(AuditQuery::for('order')->withObjectIds(1, 2));

        self::assertSame([], $this->gateway->searches);
        self::assertTrue($page->isEmpty());
    }

    public function testIterateOverNothingYieldsNothingAndOpensNothing(): void
    {
        $entries = iterator_to_array($this->reader()->iterate(AuditQuery::for('order')->matchNothing()), false);

        self::assertSame([], $entries);
        self::assertSame([], $this->gateway->searches);
        self::assertSame([], $this->gateway->pointsInTime, 'no view was opened for a traversal of nothing');
    }

    public function testARawRequestStillWearsTheExtensionsBoundary(): void
    {
        // The escape hatch for aggregations — but an escape from find()'s shape, not
        // from visibility: without this every consumer needing one "who changed it
        // most" reaches for the bare client and loses the narrowing.
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 0], 'hits' => []], 'aggregations' => ['actors' => ['buckets' => []]]];

        $visibility = new class implements QueryExtensionInterface {
            public function extend(AuditQuery $query): AuditQuery
            {
                return $query->narrowActors('u1', 'u2');
            }
        };

        $response = $this->reader(extensions: [$visibility])->raw(
            AuditQuery::for('order'),
            ['size' => 0, 'aggs' => ['actors' => ['terms' => ['field' => 'source']]]],
        );

        self::assertArrayHasKey('aggregations', $response);

        $sent = $this->gateway->searches[0]['body'];

        self::assertSame(0, $sent['size'], 'the caller\'s body is the request');
        self::assertSame(['actors' => ['terms' => ['field' => 'source']]], $sent['aggs']);
        self::assertContains(['terms' => ['source' => ['u1', 'u2']]], $sent['query']['bool']['filter'], 'and the extension\'s boundary is on it');
        self::assertContains(['term' => ['objectType' => 'order']], $sent['query']['bool']['filter']);
    }

    public function testARawBodysOwnQueryIsKeptInsideTheBoundary(): void
    {
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 0], 'hits' => []]];

        $this->reader()->raw(
            AuditQuery::for('order')->withActors('u1'),
            ['query' => ['match' => ['changes.note' => 'refund']], 'size' => 5],
        );

        $sent = $this->gateway->searches[0]['body']['query'];

        self::assertSame(['match' => ['changes.note' => 'refund']], $sent['bool']['must'][0], 'what the caller asked');
        self::assertContains(['term' => ['source' => 'u1']], $sent['bool']['filter'][0]['bool']['filter'], 'inside what the query allows');
    }

    public function testAGlobalAggregationIsRefusedBecauseItEscapesTheBoundary(): void
    {
        // The one that makes "raw() still wears the extensions' boundary" untrue: a
        // global aggregation is defined to ignore the query and count the whole search
        // context, so an extension's narrowing would be on the request and absent from
        // the numbers. Rewriting the query cannot make an arbitrary body safe, so the
        // body is constrained instead.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('global');

        $this->reader()->raw(AuditQuery::for('order'), ['size' => 0, 'aggs' => [
            'everything' => ['global' => new \stdClass(), 'aggs' => ['actors' => ['terms' => ['field' => 'source']]]],
        ]]);
    }

    public function testAGlobalAggregationIsFoundHoweverDeepItSits(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('global');

        $this->reader()->raw(AuditQuery::for('order'), ['aggs' => [
            'by_event' => ['terms' => ['field' => 'event'], 'aggs' => [
                'sneaky' => ['global' => new \stdClass()],
            ]],
        ]]);
    }

    public function testARetrievalMechanismOutsideTheQueryIsRefused(): void
    {
        // knn is combined with the query by union, not intersection: documents the
        // boundary excludes would come back beside the ones it allows.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('knn');

        $this->reader()->raw(AuditQuery::for('order'), ['knn' => ['field' => 'vector', 'k' => 5]]);
    }

    public function testAnUnknownTopLevelKeyIsRefusedRatherThanPassedOn(): void
    {
        // The list is what the reader can vouch for. Anything else may or may not
        // respect the query, and "may" is not something a visibility boundary can be
        // built on — so it is named and refused instead of forwarded.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('rank');

        $this->reader()->raw(AuditQuery::for('order'), ['rank' => ['rrf' => []]]);
    }

    public function testARuntimeFieldCannotShadowTheFieldTheBoundaryFiltersOn(): void
    {
        // A runtime field may carry the name of a mapped one, and then it shadows it
        // for the whole query: `source` filtered to "u1" is true of every document if
        // the body also says `emit('u1')` for source. The boundary would be on the
        // request and mean nothing — which is the one thing raw() promises it cannot be.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('runtime_mappings');

        $this->reader()->raw(AuditQuery::for('order'), [
            'runtime_mappings' => ['source' => ['type' => 'keyword', 'script' => ['source' => "emit('u1')"]]],
            'aggs' => ['actors' => ['terms' => ['field' => 'source']]],
        ]);
    }

    public function testRawCountsTheWindowTheWayElasticsearchDoes(): void
    {
        // from + size, not a page number reconstructed from them: from 9999 with size 2
        // reaches row 10001, and the arithmetic that turned it back into a page said
        // 10000 and let it through — to be refused by the cluster instead.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('max_result_window');

        $this->reader()->raw(AuditQuery::for('order'), ['from' => 9999, 'size' => 2]);
    }

    public function testAMissingSizeIsElasticsearchsTenNotOne(): void
    {
        // Elasticsearch defaults size to 10; validating as though it were 1 let a body
        // through that reaches ten rows deeper than the window allows.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('max_result_window');

        $this->reader()->raw(AuditQuery::for('order'), ['from' => 9995]);
    }

    public function testRawRefusesPagingThatIsNotAPosition(): void
    {
        foreach ([['from' => -1], ['size' => -5], ['from' => '10'], ['size' => 1.5]] as $body) {
            try {
                $this->reader()->raw(AuditQuery::for('order'), $body);
                self::fail('expected '.json_encode($body, JSON_THROW_ON_ERROR).' to be refused');
            } catch (InvalidQueryException $e) {
                self::assertStringContainsString('whole number', $e->getMessage());
            }
        }
    }

    public function testRawRefusesAPositionAndACursorAtOnce(): void
    {
        // search_after continues from a place; from counts rows from the beginning.
        // Elasticsearch refuses the pair, and it is cheaper to hear it here.
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('search_after');

        $this->reader()->raw(AuditQuery::for('order'), ['from' => 10, 'search_after' => ['x']]);
    }

    public function testRawCannotReachDeeperThanThePagingLimitsAllow(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('max_result_window');

        $this->reader()->raw(AuditQuery::for('order'), ['from' => 20_000, 'size' => 10]);
    }

    public function testRawCannotAskForMoreRowsThanTheReaderAllows(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('max_limit');

        $this->reader()->raw(AuditQuery::for('order'), ['size' => 5000]);
    }

    public function testAnAggregationOnlyRequestIsNotBoundedByThePageSize(): void
    {
        // size: 0 asks for no rows at all — the usual shape of an aggregation request,
        // and nothing for the row limits to object to.
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 0], 'hits' => []], 'aggregations' => []];

        $this->reader()->raw(AuditQuery::for('order'), ['size' => 0, 'aggs' => ['e' => ['terms' => ['field' => 'event']]]]);

        self::assertCount(1, $this->gateway->searches);
    }

    public function testARawRequestOverNothingCostsNothing(): void
    {
        $response = $this->reader()->raw(AuditQuery::for('order')->matchNothing(), ['aggs' => ['x' => ['terms' => ['field' => 'event']]]]);

        self::assertSame([], $this->gateway->searches);
        self::assertSame(['value' => 0, 'relation' => 'eq'], $response['hits']['total']);
        self::assertSame([], $response['hits']['hits']);

        // Documented, and pinned here because callers write against it: hits and
        // nothing else. An empty bucket list cannot be synthesised without knowing
        // which aggregation was asked for, so the answer says nothing rather than
        // guessing — and the docblock tells callers to read with ?? [].
        self::assertSame(['hits'], array_keys($response), 'no aggregations key over nothing');
    }

    public function testACorruptTimestampDoesNotBlockThePageItIsOn(): void
    {
        // Written by another tool, mangled by a reindex — the document is bad, but the
        // page holds nineteen good ones, and hydration is lenient by policy: the bad
        // entry reads with the epoch standing for "no usable time" instead of the whole
        // page becoming an exception.
        $this->gateway->respondToSearch = static fn () => [
            'hits' => ['total' => ['value' => 2], 'hits' => [
                ['_id' => 'good', '_source' => ['objectType' => 'order', 'objectId' => 1, 'event' => 'update', 'loggedAt' => '2026-08-26 10:00:00', 'source' => '7', 'changes' => []]],
                ['_id' => 'bad', '_source' => ['objectType' => 'order', 'objectId' => 2, 'event' => 'update', 'loggedAt' => 'not a date at all', 'source' => '7', 'changes' => []]],
            ]],
        ];

        $page = $this->reader()->find(AuditQuery::for('order'));

        self::assertCount(2, $page->entries);
        self::assertSame('2026-08-26T10:00:00+00:00', $page->entries[0]->loggedAt->format(\DATE_ATOM));
        self::assertSame('1970-01-01T00:00:00+00:00', $page->entries[1]->loggedAt->format(\DATE_ATOM));
    }

    public function testALoggedAtThatIsNotEvenAStringReadsTheSameWay(): void
    {
        $this->gateway->respondToSearch = static fn () => [
            'hits' => ['total' => ['value' => 1], 'hits' => [
                ['_id' => 'worse', '_source' => ['objectType' => 'order', 'objectId' => 3, 'event' => 'update', 'loggedAt' => ['gte' => 0], 'source' => '7', 'changes' => []]],
            ]],
        ];

        $page = $this->reader()->find(AuditQuery::for('order'));

        self::assertSame('1970-01-01T00:00:00+00:00', $page->entries[0]->loggedAt->format(\DATE_ATOM));
    }

    public function testTheIndexFollowsTheObjectTypeRouting(): void
    {
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 0], 'hits' => []]];
        $reader = $this->reader(new IndexResolver('audit_log', ['auth' => 'audit_auth']));

        $reader->find(AuditQuery::for('auth'));
        $reader->find(AuditQuery::for('order'));
        $reader->find(AuditQuery::any());

        self::assertSame(['audit_auth', 'audit_log', 'audit_log,audit_auth'], array_column($this->gateway->searches, 'index'), 'any() searches every routed index at once');
    }

    public function testExtensionsRewriteTheQueryBeforeItIsBuilt(): void
    {
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 0], 'hits' => []]];

        $country = new class implements QueryExtensionInterface {
            public function extend(AuditQuery $query): AuditQuery
            {
                return $query->hasOption('country') ? $query->withActors('u1', 'u2') : $query;
            }
        };

        $this->reader(extensions: [$country])->find(AuditQuery::for('order')->withOption('country', 'UA'));

        self::assertContains(['terms' => ['source' => ['u1', 'u2']]], $this->gateway->searches[0]['body']['query']['bool']['filter']);
    }

    public function testDecoratorsSeeTheWholePageOnce(): void
    {
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 2], 'hits' => [
            self::hit('a', '7'),
            self::hit('b', '8'),
        ]]];

        $names = new class implements RecordDecoratorInterface {
            public int $calls = 0;

            public function decorate(array $entries): array
            {
                ++$this->calls;
                $names = ['7' => 'Alice', '8' => 'Bob'];

                return array_map(static fn (AuditEntry $e) => $e->withExtra(['actorName' => $names[$e->actor] ?? null]), $entries);
            }
        };

        $page = $this->reader(decorators: [$names])->find(AuditQuery::for('order'));

        self::assertSame(1, $names->calls);
        self::assertSame(['Alice', 'Bob'], array_map(static fn (AuditEntry $e) => $e->extra['actorName'], $page->entries));
        self::assertSame('Alice', $page->toArray()['items'][0]['actorName']);
    }

    public function testDecoratorsAreSkippedForAnEmptyPage(): void
    {
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 0], 'hits' => []]];

        $decorator = new class implements RecordDecoratorInterface {
            public function decorate(array $entries): array
            {
                throw new \LogicException('must not be called');
            }
        };

        $page = $this->reader(decorators: [$decorator])->find(AuditQuery::for('order'));

        self::assertTrue($page->isEmpty());
        self::assertNull($page->nextCursor());
    }

    public function testIterateFollowsTheCursorUntilAShortBatch(): void
    {
        $this->gateway->respondToSearch = static function (string $index, array $body): array {
            $after = $body['search_after'] ?? null;
            $batch = match ($after[1] ?? null) {
                null => [self::hit('a', '1', 1), self::hit('b', '1', 2)],
                2 => [self::hit('c', '1', 3), self::hit('d', '1', 4)],
                4 => [self::hit('e', '1', 5)],
                default => [],
            };

            return ['hits' => ['total' => ['value' => 5], 'hits' => $batch]];
        };

        $ids = [];
        foreach ($this->reader()->iterate(AuditQuery::for('order'), batchSize: 2) as $entry) {
            $ids[] = $entry->id;
        }

        self::assertSame(['a', 'b', 'c', 'd', 'e'], $ids);
        self::assertCount(3, $this->gateway->searches);
        self::assertSame(2, $this->gateway->searches[0]['body']['size']);
        self::assertArrayNotHasKey('search_after', $this->gateway->searches[0]['body']);
        self::assertSame(['2026-08-26 10:00:00', 4], $this->gateway->searches[2]['body']['search_after']);
    }

    public function testADecoratorThatDropsEntriesDoesNotDecideWhetherMoreFollows(): void
    {
        // A full cursor page of three, one of which the decorator hides: the client still
        // has to be told there is more, and continued from the third hit, not the second.
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 30], 'hits' => [
            self::hit('a', '1', 1),
            self::hit('b', '1', 2),
            self::hit('c', '1', 3),
        ]]];

        $dropsC = new class implements RecordDecoratorInterface {
            public function decorate(array $entries): array
            {
                return array_values(array_filter($entries, static fn (AuditEntry $e) => $e->id !== 'c'));
            }
        };
        $reader = $this->reader(decorators: [$dropsC]);

        $cursorPage = $reader->find(AuditQuery::for('order')->page(1, 3)->after(['2026-08-26 10:00:00', 0]));

        self::assertCount(2, $cursorPage->entries, 'the decorator\'s word on what is shown stands');
        self::assertTrue($cursorPage->hasMore(), 'Elasticsearch returned a full page: more may follow');
        self::assertSame(['2026-08-26 10:00:00', 3], $cursorPage->nextCursor(), 'the cursor is the last hit\'s, or the hidden entry\'s successors are skipped');

        $lastNumberedPage = $reader->find(AuditQuery::for('order')->page(10, 3));

        self::assertFalse($lastNumberedPage->hasMore(), 'rows 28-30 of 30: nothing follows, whatever the decorator kept');
    }

    public function testAnExportSurvivesIteratorToArray(): void
    {
        // yield from kept the inner array's keys, every batch yielded 0..n again, and
        // iterator_to_array() without false overwrites colliding keys: of a five-record
        // export only the last batch survived — probed live at 5 documents in, 2 out.
        $this->gateway->respondToSearch = static function (string $index, array $body): array {
            $batch = match ($body['search_after'][1] ?? null) {
                null => [self::hit('a', '1', 1), self::hit('b', '2', 2)],
                2 => [self::hit('c', '3', 3), self::hit('d', '4', 4)],
                4 => [self::hit('e', '5', 5)],
                default => [],
            };

            return ['hits' => ['total' => ['value' => 5], 'hits' => $batch]];
        };

        $entries = iterator_to_array($this->reader()->iterate(AuditQuery::for('order'), batchSize: 2, consistent: false));

        self::assertCount(5, $entries, 'every batch, not the last one');
        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn (AuditEntry $e) => $e->id, $entries));
    }

    public function testIterateFollowsTheHitsEvenWhenADecoratorDropsEntries(): void
    {
        $this->gateway->respondToSearch = static function (string $index, array $body): array {
            $batch = match ($body['search_after'][1] ?? null) {
                null => [self::hit('a', '1', 1), self::hit('b', '1', 2)],
                2 => [self::hit('c', '1', 3)],
                default => [],
            };

            return ['hits' => ['total' => ['value' => 3], 'hits' => $batch]];
        };

        $dropsB = new class implements RecordDecoratorInterface {
            public function decorate(array $entries): array
            {
                return array_values(array_filter($entries, static fn (AuditEntry $e) => $e->id !== 'b'));
            }
        };

        $ids = [];
        foreach ($this->reader(decorators: [$dropsB])->iterate(AuditQuery::for('order'), batchSize: 2) as $entry) {
            $ids[] = $entry->id;
        }

        self::assertSame(['a', 'c'], $ids, 'the cursor and the stop condition come from the hits, not from what the decorators left');
        self::assertSame(['2026-08-26 10:00:00', 2], $this->gateway->searches[1]['body']['search_after']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function hit(string $id, string $actor, int $doc = 0): array
    {
        return ['_id' => $id, 'sort' => ['2026-08-26 10:00:00', $doc], '_source' => [
            'objectType' => 'order', 'objectId' => 1, 'event' => 'update', 'loggedAt' => '2026-08-26 10:00:00', 'source' => $actor, 'changes' => [],
        ]];
    }

    /**
     * @param iterable<QueryExtensionInterface>  $extensions
     * @param iterable<RecordDecoratorInterface> $decorators
     */
    private function reader(?IndexResolver $resolver = null, iterable $extensions = [], iterable $decorators = []): AuditReader
    {
        return new AuditReader($this->gateway, $resolver ?? new IndexResolver('audit_log'), extensions: $extensions, decorators: $decorators);
    }
}
