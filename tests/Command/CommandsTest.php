<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Command;

use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends TestCase
{
    private InMemoryGateway $gateway;
    private IndexResolver $resolver;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->resolver = new IndexResolver('audit_log', ['auth' => 'audit_auth']);
    }

    public function testCreateMakesEveryRoutedIndexWithEnricherFields(): void
    {
        $tester = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('audit_log created', $tester->getDisplay());
        self::assertStringContainsString('audit_auth created', $tester->getDisplay());
        self::assertSame(['type' => 'integer'], $this->gateway->indices['audit_log']['mappings']['properties']['salesType']);
        self::assertSame(['type' => 'keyword'], $this->gateway->indices['audit_auth']['mappings']['properties']['objectId']);
    }

    public function testCreateLeavesExistingIndicesAlone(): void
    {
        $this->gateway->indices['audit_log'] = ['mappings' => ['properties' => ['legacy' => ['type' => 'keyword']]]];

        $tester = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('audit_log already exists', $tester->getDisplay());
        self::assertSame(['legacy' => ['type' => 'keyword']], $this->gateway->indices['audit_log']['mappings']['properties']);
    }

    public function testCreateCanDumpTheDefinitionInstead(): void
    {
        $tester = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dump' => true]));
        self::assertSame([], $this->gateway->indices);
        self::assertStringContainsString('"enabled": false', $tester->getDisplay());
    }

    public function testCreateReportsAnUnreachableCluster(): void
    {
        $this->gateway->failWith = new \RuntimeException('refused');

        $tester = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('refused', $tester->getDisplay());
    }

    public function testCheckPassesWhenEverythingIsThere(): void
    {
        (new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher()])))->execute([]);

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('audit_log ok', $tester->getDisplay());
        self::assertStringContainsString('audit_auth ok', $tester->getDisplay());
    }

    public function testCheckFlagsMissingIndicesAndMissingFields(): void
    {
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->toArray(); // created before the enricher existed

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher()]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('audit_log exists but lacks mapping for: salesType', $tester->getDisplay());
        self::assertStringContainsString('audit_auth missing', $tester->getDisplay());
    }

    public function testCheckFlagsAFieldMappedWithTheWrongType(): void
    {
        // What Elasticsearch guesses when a record lands before audit:index:create ran.
        $guessed = (new IndexDefinition())->toArray();
        $guessed['mappings']['properties']['loggedAt'] = ['type' => 'text'];
        $guessed['mappings']['properties']['salesType'] = ['type' => 'long'];
        $this->gateway->indices['audit_log'] = $guessed;
        $this->gateway->indices['audit_auth'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher()]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('loggedAt is text, expected date', $tester->getDisplay());
        self::assertStringContainsString('salesType is long, expected integer', $tester->getDisplay());
        self::assertStringContainsString('audit_auth exists but lacks mapping for: salesType', $tester->getDisplay());
    }

    public function testCheckReportsAFailureOnOneIndexAndGoesOn(): void
    {
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->toArray();
        $this->gateway->indices['audit_auth'] = (new IndexDefinition())->toArray();
        $this->gateway->failOn = ['mapping' => new \RuntimeException('shard unavailable')];

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('audit_log: Elasticsearch is unreachable: shard unavailable', $tester->getDisplay());
        self::assertStringContainsString('audit_auth: Elasticsearch is unreachable: shard unavailable', $tester->getDisplay(), 'the second index is still checked');
    }

    public function testCheckFailsFastWhenTheClusterIsDown(): void
    {
        $this->gateway->failWith = new \RuntimeException('refused');

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('unreachable', $tester->getDisplay());
    }

    private function enricher(): AuditEnricherInterface
    {
        return new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record;
            }

            public function mapping(): array
            {
                return ['salesType' => ['type' => 'integer']];
            }
        };
    }
}
