<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
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
