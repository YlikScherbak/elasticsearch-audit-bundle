<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
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
 * What only a real cluster can confirm: one _bulk request may address several
 * indices and reports refusals per item, and a point in time really freezes what
 * an export sees.
 */
#[Group('integration')]
final class BulkAndPointInTimeTest extends ElasticsearchTestCase
{
    private ElasticsearchGateway $gateway;
    private string $index;
    private string $authIndex;
    private IndexResolver $resolver;

    protected function setUp(): void
    {
        $this->gateway = new ElasticsearchGateway(self::client());
        $this->index = $this->scratchIndex();
        $this->authIndex = $this->scratchIndex();
        $this->resolver = new IndexResolver($this->index, ['auth' => $this->authIndex]);

        $this->gateway->createIndex($this->index, (new IndexDefinition(IndexDefinition::OBJECT_ID_INTEGER))->toArray());
        $this->gateway->createIndex($this->authIndex, (new IndexDefinition())->toArray());
    }

    protected function tearDown(): void
    {
        $this->dropIndex($this->index);
        $this->dropIndex($this->authIndex);
    }

    public function testOneBulkRequestWritesToSeveralIndices(): void
    {
        $result = $this->gateway->bulk([
            ['index' => $this->index, 'document' => self::document('order', 1), 'id' => 'a'],
            ['index' => $this->authIndex, 'document' => self::document('auth', 'alice'), 'id' => 'b'],
            ['index' => $this->index, 'document' => self::document('order', 2), 'id' => 'c'],
        ]);

        self::assertFalse($result->hasFailures());
        self::assertSame(3, $result->succeeded());

        self::client()->indices()->refresh(['index' => $this->index.','.$this->authIndex]);

        self::assertSame(2, $this->gateway->search($this->index, ['query' => ['match_all' => new \stdClass()]])['hits']['total']['value']);
        self::assertSame(1, $this->gateway->search($this->authIndex, ['query' => ['match_all' => new \stdClass()]])['hits']['total']['value']);
    }

    public function testARefusedItemIsReportedByPositionAndTheOthersAreWritten(): void
    {
        // objectId is mapped as integer in $this->index: "alice" cannot go there.
        $result = $this->gateway->bulk([
            ['index' => $this->index, 'document' => self::document('order', 1), 'id' => 'a'],
            ['index' => $this->index, 'document' => self::document('order', 'alice'), 'id' => 'b'],
            ['index' => $this->index, 'document' => self::document('order', 3), 'id' => 'c'],
        ]);

        self::assertSame([1], array_keys($result->failures));
        self::assertSame(400, $result->failures[1]['status']);
        self::assertStringContainsString('objectId', $result->failures[1]['reason']);
        self::assertSame(2, $result->succeeded());
    }

    public function testABulkToAMissingIndexIsRefusedBeforeAnythingIsWritten(): void
    {
        $missing = $this->scratchIndex();

        try {
            $this->gateway->bulk([
                ['index' => $this->index, 'document' => self::document('order', 1), 'id' => 'a'],
                ['index' => $missing, 'document' => self::document('order', 2), 'id' => 'b'],
            ]);
            self::fail('expected IndexNotFoundException');
        } catch (IndexNotFoundException) {
        }

        self::client()->indices()->refresh(['index' => $this->index]);
        self::assertSame(0, $this->gateway->search($this->index, ['query' => ['match_all' => new \stdClass()]])['hits']['total']['value'], 'nothing was written, not even to the index that exists');
        self::assertFalse($this->gateway->indexExists($missing), 'and the missing one was not created on the fly');
    }

    public function testTheWriterSendsAFlushAsOneBulk(): void
    {
        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, $this->resolver, new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Throw);

        $writer->writeAll([
            new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]),
            new AuditRecord('auth', 'alice', 'login_failed'),
        ]);

        self::client()->indices()->refresh(['index' => $this->index.','.$this->authIndex]);

        $reader = new AuditReader($this->gateway, $this->resolver);
        self::assertSame(2, $reader->find(AuditQuery::any())->total);
    }

    public function testAPointInTimeFreezesWhatTheExportSees(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->gateway->index($this->index, self::document('order', $i, sprintf('2026-08-28 10:00:%02d', $i)), (string) $i);
        }
        self::client()->indices()->refresh(['index' => $this->index]);

        $reader = new AuditReader($this->gateway, $this->resolver, pointInTimeKeepAlive: '30s');
        $seen = [];

        foreach ($reader->iterate(AuditQuery::for('order')->oldestFirst(), batchSize: 2) as $entry) {
            $seen[] = $entry->objectId;

            if ($entry->objectId === 2) {
                // Written and made searchable while the export is running.
                $this->gateway->index($this->index, self::document('order', 99, '2026-08-28 10:00:00'), '99', refresh: true);
            }
        }

        self::assertSame([1, 2, 3, 4, 5], $seen, 'the record that arrived mid-export is not in the frozen view');
        self::assertSame(6, $reader->find(AuditQuery::for('order'))->total, 'a fresh search sees it');
    }

    public function testAnyExportsEveryRoutedIndexFromOnePointInTime(): void
    {
        $this->gateway->index($this->index, self::document('order', 1, '2026-08-28 10:00:01'), '1');
        $this->gateway->index($this->authIndex, self::document('auth', 'alice', '2026-08-28 10:00:02'), 'a');
        self::client()->indices()->refresh(['index' => $this->index.','.$this->authIndex]);

        $reader = new AuditReader($this->gateway, $this->resolver, pointInTimeKeepAlive: '30s');
        $types = [];

        foreach ($reader->iterate(AuditQuery::any()->oldestFirst(), batchSize: 1) as $entry) {
            $types[] = $entry->objectType;
        }

        self::assertSame(['order', 'auth'], $types, 'one point in time spans both indices and the cursor walks across them');
    }

    public function testAnInconsistentExportSeesTheLiveIndex(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->gateway->index($this->index, self::document('order', $i, sprintf('2026-08-28 10:00:%02d', $i)), (string) $i);
        }
        self::client()->indices()->refresh(['index' => $this->index]);

        $reader = new AuditReader($this->gateway, $this->resolver);
        $seen = [];

        foreach ($reader->iterate(AuditQuery::for('order')->oldestFirst(), batchSize: 1, consistent: false) as $entry) {
            $seen[] = $entry->objectId;

            if ($entry->objectId === 1) {
                $this->gateway->index($this->index, self::document('order', 4, '2026-08-28 10:00:04'), '4', refresh: true);
            }
        }

        self::assertSame([1, 2, 3, 4], $seen, 'without a point in time the export picks up what arrives after its start');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * A document shaped like the writer's own: the record id travels inside it as well
     * as being the document id. Without it Elasticsearch answers with null for that
     * sort value, and a cursor cannot continue from a position two records can share —
     * which is a real refusal, not a fixture detail worth working around.
     */
    private static function document(string $type, int|string $id, string $at = '2026-08-28 10:00:00'): array
    {
        return ['id' => sprintf('%s-%s-%s', $type, $id, str_replace([' ', ':'], '', $at)), 'objectType' => $type, 'objectId' => $id, 'event' => 'update', 'loggedAt' => $at, 'source' => 'system', 'changes' => []];
    }
}
