<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
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
