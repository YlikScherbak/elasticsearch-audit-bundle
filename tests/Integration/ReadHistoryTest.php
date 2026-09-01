<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\Attributes\Group;

/**
 * Filters, sorting and both paging styles against a real cluster, on documents
 * written by the real writer — the request bodies QueryBuilder produces are only
 * proven correct here.
 */
#[Group('integration')]
final class ReadHistoryTest extends ElasticsearchTestCase
{
    private ElasticsearchGateway $gateway;
    private string $index;
    private string $userIndex;
    private AuditReader $reader;

    protected function setUp(): void
    {
        $this->gateway = new ElasticsearchGateway(self::client());
        $this->index = $this->scratchIndex();
        $this->userIndex = $this->scratchIndex();
        // "user" lives in its own index: any() has to look there too.
        $resolver = new IndexResolver($this->index, ['user' => $this->userIndex]);
        $this->reader = new AuditReader($this->gateway, $resolver);
        $this->gateway->createIndex($this->userIndex, (new IndexDefinition())->toArray());

        $this->gateway->createIndex($this->index, (new IndexDefinition())->withProperties(['salesType' => ['type' => 'integer']])->toArray());

        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, $resolver, new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Throw);

        // 25 order updates one minute apart by two actors, plus a user record and a create.
        for ($i = 0; $i < 25; ++$i) {
            $writer->record('order', 100 + $i, 'update', ['status' => new Change('a', 'b')], ['salesType' => $i % 2], new \DateTimeImmutable(sprintf('2026-08-26 10:%02d:00', $i), new \DateTimeZone('UTC')), $i < 20 ? 'alice' : 'bob');
        }
        $writer->record('order', 100, 'create', [], ['salesType' => 0], new \DateTimeImmutable('2026-08-26 09:00:00', new \DateTimeZone('UTC')), 'alice');
        $writer->record('user', 'u1', 'update', ['name' => new Change('x', 'y')], [], new \DateTimeImmutable('2026-08-26 10:30:00', new \DateTimeZone('UTC')), 'bob');

        self::client()->indices()->refresh(['index' => $this->index.','.$this->userIndex]);
    }

    protected function tearDown(): void
    {
        $this->dropIndex($this->index);
        $this->dropIndex($this->userIndex);
    }

    public function testFiltersCombineAndTheTotalIsExact(): void
    {
        $page = $this->reader->find(
            AuditQuery::for('order')
                ->withEvents('update')
                ->withActors('alice')
                ->where('salesType', 1)
                ->between(new \DateTimeImmutable('2026-08-26 10:05:00', new \DateTimeZone('UTC')), new \DateTimeImmutable('2026-08-26 10:15:00', new \DateTimeZone('UTC')))
        );

        // odd minutes 5..15 → 5, 7, 9, 11, 13, 15
        self::assertSame(6, $page->total);
        self::assertSame([115, 113, 111, 109, 107, 105], array_map(static fn (AuditEntry $e) => $e->objectId, $page->entries), 'newest first');
        self::assertSame(1, $page->entries[0]->attribute('salesType'));
    }

    public function testObjectHistoryIncludesEveryEventOfThatObject(): void
    {
        $page = $this->reader->find(AuditQuery::for('order')->withObjectId(100)->oldestFirst());

        self::assertSame(['create', 'update'], array_map(static fn (AuditEntry $e) => $e->event, $page->entries));
        self::assertSame(['old' => 'a', 'new' => 'b'], $page->entries[1]->changes['status']);
    }

    public function testAnyReadsAcrossObjectTypes(): void
    {
        $page = $this->reader->find(AuditQuery::any()->withActors('bob'));

        self::assertSame(6, $page->total); // 5 orders + 1 user
        self::assertContains('user', array_map(static fn (AuditEntry $e) => $e->objectType, $page->entries));
    }

    public function testOffsetPagingAndCursorPagingAgree(): void
    {
        $query = AuditQuery::for('order')->withEvents('update')->oldestFirst();

        $byOffset = [];
        for ($p = 1; $p <= 3; ++$p) {
            $byOffset[] = array_map(static fn (AuditEntry $e) => $e->objectId, $this->reader->find($query->page($p, 10))->entries);
        }

        self::assertSame([10, 10, 5], array_map('count', $byOffset));

        $byCursor = [];
        $q = $query->page(1, 10);
        do {
            $page = $this->reader->find($q);
            $byCursor[] = array_map(static fn (AuditEntry $e) => $e->objectId, $page->entries);
            $cursor = $page->nextCursor();
            $q = $cursor === null ? null : $q->after($cursor);
        } while ($q !== null && !$page->isEmpty());

        self::assertSame(array_merge(...$byOffset), array_merge(...array_filter($byCursor)));
        self::assertSame(range(100, 124), array_merge(...$byOffset));
    }

    public function testAClientWalksWithTokensAndStopsWhereTheDataDoes(): void
    {
        [$ids, $requests, $last] = $this->walkWithTokens(AuditQuery::for('order')->withEvents('update')->oldestFirst()->page(1, 10));

        self::assertSame(range(100, 124), $ids);
        self::assertSame(3, $requests, 'ten, ten, five — and no empty request at the end');
        self::assertFalse($last->hasMore());
    }

    public function testTheSameWalkNewestFirst(): void
    {
        // The tie-breaker has to hold in both directions, or a boundary row is dropped
        // or served twice — which only a real cluster can prove.
        [$ids, $requests, $last] = $this->walkWithTokens(AuditQuery::for('order')->withEvents('update')->newestFirst()->page(1, 10));

        self::assertSame(array_reverse(range(100, 124)), $ids);
        self::assertSame(3, $requests);
        self::assertFalse($last->hasMore());
    }

    public function testAQueryThatMatchesNothingIsOneRequestAndNoCursor(): void
    {
        [$ids, $requests, $last] = $this->walkWithTokens(AuditQuery::for('order')->withActors('nobody')->page(1, 10));

        self::assertSame([], $ids);
        self::assertSame(1, $requests);
        self::assertSame(0, $last->total);
        self::assertSame(0, $last->totalPages());
        self::assertSame(0, $last->maxReachablePage());
        self::assertFalse($last->hasMore());
        self::assertNull($last->toArray()['pagination']['nextCursor']);
    }

    /**
     * Exactly what a browser does: send back the string it was given, nothing else.
     *
     * @return array{0: list<int|string>, 1: int, 2: \Borsche\ElasticsearchAuditBundle\Model\AuditPage}
     */
    private function walkWithTokens(AuditQuery $query): array
    {
        $ids = [];
        $requests = 0;
        $token = null;

        do {
            $page = $this->reader->find($token === null ? $query : $query->afterToken($token));
            ++$requests;

            foreach ($page->entries as $entry) {
                $ids[] = $entry->objectId;
            }

            $token = $page->toArray()['pagination']['nextCursor'];
        } while ($token !== null);

        return [$ids, $requests, $page];
    }

    public function testACursorAcrossIndicesDoesNotStepOverARecord(): void
    {
        // Two records with the same second and the same id, in two routed indices —
        // which is what an application invites the moment it chooses its own record ids.
        // Sorted by loggedAt and id alone the pair is not unique, and search_after walks
        // past one of them: on a real cluster the second document never came back.
        $at = new \DateTimeImmutable('2026-08-26 11:00:00', new \DateTimeZone('UTC'));

        $this->gateway->index($this->index, (new AuditRecord('order', 'twin', 'update', $at, 'alice', [], [], 'same-id'))->toDocument(), 'same-id');
        $this->gateway->index($this->userIndex, (new AuditRecord('user', 'twin', 'update', $at, 'alice', [], [], 'same-id'))->toDocument(), 'same-id');
        self::client()->indices()->refresh(['index' => $this->index.','.$this->userIndex]);

        $query = AuditQuery::any()->between($at, $at)->page(1, 1);

        $first = $this->reader->find($query);
        $second = $this->reader->find($query->after($first->nextCursor() ?? []));

        self::assertCount(1, $first->entries);
        self::assertCount(1, $second->entries, 'the twin in the other index is still there');
        self::assertNotSame($first->entries[0]->objectType, $second->entries[0]->objectType, 'and it is the other one');
    }

    public function testIterateStreamsEverythingInOrder(): void
    {
        $ids = [];
        foreach ($this->reader->iterate(AuditQuery::for('order')->withEvents('update')->oldestFirst(), batchSize: 7) as $entry) {
            $ids[] = $entry->objectId;
        }

        self::assertSame(range(100, 124), $ids);
    }

    public function testAMissingIndexSurfacesAsSuch(): void
    {
        $this->expectException(IndexNotFoundException::class);

        (new AuditReader($this->gateway, new IndexResolver($this->scratchIndex())))->find(AuditQuery::any());
    }
}
