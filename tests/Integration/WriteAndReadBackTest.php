<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The whole write path against a real cluster: the command creates the index with
 * the enricher's field, the writer records through the gateway, and the document
 * comes back filterable by the base fields and the enriched attribute.
 */
#[Group('integration')]
final class WriteAndReadBackTest extends ElasticsearchTestCase
{
    private ElasticsearchGateway $gateway;
    private string $index;
    private string $authIndex;
    private IndexResolver $resolver;
    private AuditEnricherInterface $enricher;

    protected function setUp(): void
    {
        $this->gateway = new ElasticsearchGateway(self::client());
        $this->index = $this->scratchIndex();
        $this->authIndex = $this->scratchIndex();
        $this->resolver = new IndexResolver($this->index, ['auth' => $this->authIndex]);
        $this->enricher = new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return $record->objectType === 'order';
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes(['salesType' => 3]);
            }

            public function mapping(): array
            {
                return ['salesType' => ['type' => 'integer']];
            }
        };
    }

    protected function tearDown(): void
    {
        $this->dropIndex($this->index);
        $this->dropIndex($this->authIndex);
    }

    public function testCreateWriteFilterAndCheck(): void
    {
        $create = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher]));
        self::assertSame(Command::SUCCESS, $create->execute([]), $create->getDisplay());

        self::assertSame(['type' => 'integer'], $this->gateway->mapping($this->index)['salesType']);
        self::assertSame(['type' => 'object', 'enabled' => false], $this->gateway->mapping($this->index)['changes']);

        $writer = $this->writer();
        $writer->record('order', 42, AuditEvent::UPDATE, ['status' => new Change('new', 'paid')]);
        $writer->record('order', 43, AuditEvent::CREATE);
        $writer->record('user', 7, AuditEvent::UPDATE, ['name' => new Change('a', 'b')]);
        $writer->record('auth', 'alice', 'login_failed', ['ip' => '10.0.0.1']);

        self::client()->indices()->refresh(['index' => $this->index.','.$this->authIndex]);

        $orders = $this->gateway->search($this->index, ['query' => ['term' => ['salesType' => 3]], 'sort' => ['objectId' => 'asc']]);
        self::assertSame(2, $orders['hits']['total']['value']);
        self::assertSame([
            'objectType' => 'order',
            'objectId' => 42,
            'event' => 'update',
            'loggedAt' => '2026-08-26 12:00:00',
            'source' => 'system',
            'changes' => ['status' => ['old' => 'new', 'new' => 'paid']],
            'salesType' => 3,
        ], $orders['hits']['hits'][0]['_source']);

        $byDate = $this->gateway->search($this->index, ['query' => ['range' => ['loggedAt' => ['gte' => '2026-08-26 00:00:00', 'lt' => '2026-08-27 00:00:00']]]]);
        self::assertSame(3, $byDate['hits']['total']['value']);

        $auth = $this->gateway->search($this->authIndex, ['query' => ['match_all' => new \stdClass()]]);
        self::assertSame(1, $auth['hits']['total']['value']);
        self::assertSame('alice', $auth['hits']['hits'][0]['_source']['objectId']);

        $check = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher]));
        self::assertSame(Command::SUCCESS, $check->execute([]), $check->getDisplay());
    }

    public function testCheckNoticesAMappingThatPredatesAnEnricher(): void
    {
        $this->gateway->createIndex($this->index, (new IndexDefinition())->toArray());
        $this->gateway->createIndex($this->authIndex, (new IndexDefinition())->toArray());

        $check = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher]));

        self::assertSame(Command::FAILURE, $check->execute([]));
        self::assertStringContainsString('lacks mapping for: salesType', $check->getDisplay());
    }

    public function testAMissingIndexIsReportedAsSuch(): void
    {
        $this->expectException(IndexNotFoundException::class);

        $this->gateway->search($this->index, ['query' => ['match_all' => new \stdClass()]]);
    }

    public function testWithTheThrowPolicyAMissingIndexIsNotSwallowed(): void
    {
        // Elasticsearch auto-creates indices on write by default, so point at a name it refuses.
        $writer = new AuditWriter(
            $t = new SyncTransport($this->gateway),
            $t,
            new IndexResolver('-invalid-name-'),
            new ChainActorResolver([]),
            new FrozenClock(),
            [],
            FailurePolicy::Throw,
        );

        $this->expectException(\Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException::class);

        $writer->record('order', 1, AuditEvent::CREATE);
    }

    private function writer(): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, $this->resolver, new ChainActorResolver([], 'system'), new FrozenClock(), [$this->enricher], FailurePolicy::Throw);
    }
}
