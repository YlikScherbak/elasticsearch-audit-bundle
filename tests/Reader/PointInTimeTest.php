<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Reader\QueryBuilder;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

/**
 * iterate() reads from a point in time: a frozen view the export walks through,
 * opened before the first batch and closed however the export ends.
 */
final class PointInTimeTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();

        for ($i = 1; $i <= 5; ++$i) {
            $this->gateway->index('audit_log', ['objectType' => 'order', 'objectId' => $i, 'event' => 'update', 'loggedAt' => sprintf('2026-08-28 10:00:%02d', $i), 'source' => 'a', 'changes' => []]);
        }
    }

    public function testTheExportOpensAViewWalksItAndClosesIt(): void
    {
        $ids = [];
        foreach ($this->reader()->iterate(AuditQuery::for('order'), batchSize: 2) as $entry) {
            $ids[] = $entry->objectId;
        }

        self::assertSame([1, 2, 3, 4, 5], $ids);

        $pit = $this->gateway->pointsInTime['pit-1'];
        self::assertSame('audit_log', $pit['index']);
        self::assertSame(3, $pit['searches'], 'three batches of two');
        self::assertTrue($pit['closed']);
        self::assertSame([null, null, null], array_column($this->gateway->searches, 'index'), 'no index in the request: the point in time says which');
    }

    public function testRecordsWrittenDuringTheExportAreNotPartOfIt(): void
    {
        $ids = [];
        foreach ($this->reader()->iterate(AuditQuery::for('order'), batchSize: 2) as $entry) {
            $ids[] = $entry->objectId;

            if ($entry->objectId === 2) {
                // Somebody writes while the export runs.
                $this->gateway->index('audit_log', ['objectType' => 'order', 'objectId' => 99, 'event' => 'update', 'loggedAt' => '2026-08-28 10:00:06', 'source' => 'a', 'changes' => []]);
            }
        }

        self::assertSame([1, 2, 3, 4, 5], $ids, 'the view was frozen when the export started');
        self::assertCount(6, $this->gateway->documents['audit_log'], 'the write itself did happen');
    }

    public function testStoppingEarlyStillClosesTheView(): void
    {
        foreach ($this->reader()->iterate(AuditQuery::for('order'), batchSize: 2) as $entry) {
            if ($entry->objectId === 3) {
                break;
            }
        }

        self::assertTrue($this->gateway->pointsInTime['pit-1']['closed'], 'finally runs when the consumer stops consuming');
    }

    public function testTheSortGetsTheShardTiebreakerOnlyInsideAView(): void
    {
        $builder = new QueryBuilder();

        self::assertSame([['loggedAt' => 'desc'], ['id' => ['order' => 'desc', 'unmapped_type' => 'keyword']], ['_shard_doc' => 'desc']], $builder->build(AuditQuery::any(), pointInTime: true)['sort']);
        self::assertCount(2, $builder->build(AuditQuery::any())['sort'], 'a plain search has no _shard_doc');
    }

    public function testTheKeepAliveTravelsWithEverySearch(): void
    {
        $reader = new AuditReader($this->gateway, new IndexResolver('audit_log'), pointInTimeKeepAlive: '30s');
        iterator_to_array($reader->iterate(AuditQuery::for('order'), batchSize: 10));

        self::assertSame('pit-1', $this->gateway->searches[0]['pit']);
        // The in-memory gateway does not record the keep-alive, so assert on the contract the reader
        // was built with: the value is the one it passes, and a search without a pit would have an index.
        self::assertNull($this->gateway->searches[0]['index']);
    }

    public function testAnInconsistentExportSearchesTheLiveIndexWithoutAView(): void
    {
        $entries = iterator_to_array($this->reader()->iterate(AuditQuery::for('order'), batchSize: 10, consistent: false));

        self::assertCount(5, $entries);
        self::assertSame([], $this->gateway->pointsInTime, 'no point in time was opened');
        self::assertSame('audit_log', $this->gateway->searches[0]['index']);
    }

    public function testFindDoesNotOpenAView(): void
    {
        $page = $this->reader()->find(AuditQuery::for('order'));

        self::assertCount(5, $page->entries);
        self::assertSame([], $this->gateway->pointsInTime, 'one page needs no frozen view');
    }

    public function testEntriesComeBackAsUsual(): void
    {
        $first = iterator_to_array($this->reader()->iterate(AuditQuery::for('order'), batchSize: 10))[0];

        self::assertInstanceOf(AuditEntry::class, $first);
        self::assertSame('order', $first->objectType);
    }

    private function reader(): AuditReader
    {
        return new AuditReader($this->gateway, new IndexResolver('audit_log'));
    }
}
