<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Command;

use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Command\SyncIndexCommand;
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

    public function testCheckFlagsADateWhoseFormatDrifted(): void
    {
        // The type is right and the format is not: "date" without our format expects ISO
        // strings, so an index that passes a type-only check refuses every record the
        // writer sends. This is exactly the drift the command exists to see coming.
        $bare = (new IndexDefinition())->toArray();
        $bare['mappings']['properties']['loggedAt'] = ['type' => 'date'];
        $this->gateway->indices['audit_log'] = $bare;

        $other = (new IndexDefinition())->toArray();
        $other['mappings']['properties']['loggedAt'] = ['type' => 'date', 'format' => 'epoch_millis'];
        $this->gateway->indices['audit_auth'] = $other;

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('loggedAt format is not set, expected "yyyy-MM-dd HH:mm:ss"', $tester->getDisplay());
        self::assertStringContainsString('loggedAt format is "epoch_millis", expected "yyyy-MM-dd HH:mm:ss"', $tester->getDisplay());
    }

    public function testCheckFlagsChangesThatBecameIndexed(): void
    {
        // What auto-creation guesses for "changes": a plain object, indexed — every
        // changed field of every entity becomes a mapping entry until the mapping
        // itself overflows. The type matches; the lost "enabled: false" is the drift.
        $guessed = (new IndexDefinition())->toArray();
        $guessed['mappings']['properties']['changes'] = ['properties' => ['title' => ['type' => 'text']]];
        $this->gateway->indices['audit_log'] = $guessed;
        $this->gateway->indices['audit_auth'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('changes enabled is not set, expected false', $tester->getDisplay());
        self::assertStringContainsString('audit_auth ok', $tester->getDisplay());
    }

    public function testCheckLooksInsideNestedProperties(): void
    {
        // The enricher grew a field and another changed its mind about the type; both
        // live inside an object, where a top-level comparison never looks.
        $actual = (new IndexDefinition())->withProperties(['context' => ['properties' => ['ip' => ['type' => 'keyword']]]])->toArray();
        $this->gateway->indices['audit_log'] = $actual;
        $this->gateway->indices['audit_auth'] = $actual;

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->nestedEnricher()]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('context.ip is keyword, expected ip', $tester->getDisplay());
        self::assertStringContainsString('lacks mapping for: context.city', $tester->getDisplay());
    }

    public function testCheckAcceptsANestedMappingItCreatedItself(): void
    {
        (new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->nestedEnricher()])))->execute([]);

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->nestedEnricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
    }

    public function testSyncAddsTheFieldsAnIndexLacks(): void
    {
        // The index was created before the enricher existed — the story audit:check
        // tells with "lacks mapping for" — and sync is the command that acts on it.
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->toArray();
        $this->gateway->indices['audit_auth'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester(new SyncIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('audit_log: added mapping for salesType', $tester->getDisplay());
        self::assertSame(['type' => 'integer'], $this->gateway->indices['audit_log']['mappings']['properties']['salesType']);
        self::assertSame(['type' => 'integer'], $this->gateway->indices['audit_auth']['mappings']['properties']['salesType']);
    }

    public function testSyncReachesAFieldInsideAnObject(): void
    {
        // context exists, context.city does not: the addition travels as a partial
        // parent, which Elasticsearch merges without touching context.ip.
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->withProperties(['context' => ['properties' => ['ip' => ['type' => 'ip']]]])->toArray();
        $this->gateway->indices['audit_auth'] = (new IndexDefinition())->withProperties(['context' => ['properties' => ['ip' => ['type' => 'ip'], 'city' => ['type' => 'keyword']]]])->toArray();

        $tester = new CommandTester(new SyncIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->nestedEnricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('audit_log: added mapping for context.city', $tester->getDisplay());
        self::assertSame(['type' => 'keyword'], $this->gateway->indices['audit_log']['mappings']['properties']['context']['properties']['city']);
        self::assertSame(['type' => 'ip'], $this->gateway->indices['audit_log']['mappings']['properties']['context']['properties']['ip'], 'the sibling was not touched');
        self::assertStringContainsString('audit_auth ok', $tester->getDisplay());
    }

    public function testSyncRefusesToTouchAFieldMappedDifferently(): void
    {
        // A changed type is a reindex, and no command should pretend otherwise. The
        // missing field on the same index is still added: what can be fixed, is.
        $drifted = (new IndexDefinition())->toArray();
        $drifted['mappings']['properties']['loggedAt'] = ['type' => 'text'];
        $this->gateway->indices['audit_log'] = $drifted;
        $this->gateway->indices['audit_auth'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester(new SyncIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [$this->enricher()]));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('loggedAt is text, expected date', $tester->getDisplay());
        self::assertStringContainsString('reindex', $tester->getDisplay());
        self::assertSame(['type' => 'integer'], $this->gateway->indices['audit_log']['mappings']['properties']['salesType'], 'the addable field was still added');
        self::assertSame(['type' => 'text'], $this->gateway->indices['audit_log']['mappings']['properties']['loggedAt'], 'the drifted one was left alone');
    }

    public function testSyncSendsAMissingIndexToCreate(): void
    {
        $tester = new CommandTester(new SyncIndexCommand($this->gateway, $this->resolver, new IndexDefinition()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('audit_log missing — run audit:index:create', $tester->getDisplay());
    }

    public function testCheckComparesTheIndexWindowAgainstTheReaders(): void
    {
        // The two settings must move together: reader.max_result_window promises pages
        // the index then refuses, and the drift surfaces on a deep page in production.
        $small = (new IndexDefinition())->withSettings(['max_result_window' => 5000])->toArray();
        $this->gateway->indices['audit_log'] = $small;
        $this->gateway->indices['audit_auth'] = (new IndexDefinition())->toArray(); // no explicit window: Elasticsearch's default 10 000

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [], maxResultWindow: 10_000));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('index.max_result_window (5000) on audit_log is below reader.max_result_window (10000)', $tester->getDisplay());
        self::assertStringContainsString('audit_auth ok', $tester->getDisplay());
    }

    public function testCheckIsQuietWhenTheWindowsAgree(): void
    {
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->withSettings(['max_result_window' => 20_000])->toArray();
        $this->gateway->indices['audit_auth'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [], maxResultWindow: 10_000));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
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

    private function nestedEnricher(): AuditEnricherInterface
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
                return ['context' => ['properties' => ['ip' => ['type' => 'ip'], 'city' => ['type' => 'keyword']]]];
            }
        };
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
