<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Command;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Command\SyncIndexCommand;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ScopedEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An enricher that named its object types, and the three commands that used to fold
 * every enricher into every index.
 *
 * Reported from an application routing `auth` and `warehouse-stock` to indices of
 * their own: audit:check said `orderCountry` was missing from all three, and
 * audit:index:sync added it to all three. Two of those fields can never be written —
 * `order` is not routed there — so the check could not be green without a mapping
 * that describes what cannot happen.
 */
final class ScopedEnricherMappingTest extends TestCase
{
    private InMemoryGateway $gateway;
    private IndexResolver $resolver;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->resolver = new IndexResolver('audit_log', ['auth' => 'audit_auth_log', 'warehouse-stock' => 'audit_stock_log']);
    }

    public function testAnIndexIsCreatedWithTheFieldsItsOwnRecordsCanHave(): void
    {
        $tester = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [new OrderCountryEnricher(), new EveryTypeEnricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertArrayHasKey('orderCountry', $this->gateway->indices['audit_log']['mappings']['properties']);
        self::assertArrayNotHasKey('orderCountry', $this->gateway->indices['audit_auth_log']['mappings']['properties']);
        self::assertArrayNotHasKey('orderCountry', $this->gateway->indices['audit_stock_log']['mappings']['properties']);

        foreach (['audit_log', 'audit_auth_log', 'audit_stock_log'] as $index) {
            self::assertArrayHasKey('tenant', $this->gateway->indices[$index]['mappings']['properties'], $index.' lost the fields of an enricher that named no types');
        }
    }

    public function testCheckDoesNotAskForFieldsAnIndexCanNeverHave(): void
    {
        $this->createEveryIndex();

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [new OrderCountryEnricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('lacks mapping', $tester->getDisplay());
    }

    public function testCheckStillAsksForThemWhereTheyBelong(): void
    {
        // The other half: scoping must not turn the check into one that never says
        // anything. The order index really is missing the field here.
        $this->createEveryIndex();
        unset($this->gateway->indices['audit_log']['mappings']['properties']['orderCountry']);

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition(), [new OrderCountryEnricher()]));

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('audit_log', $display);
        self::assertStringContainsString('orderCountry', $display);
        self::assertStringNotContainsString('audit_auth_log exists but lacks', $display);
    }

    public function testSyncAddsTheFieldOnlyWhereItsRecordsGo(): void
    {
        $this->createEveryIndex();

        foreach (['audit_log', 'audit_auth_log', 'audit_stock_log'] as $index) {
            unset($this->gateway->indices[$index]['mappings']['properties']['orderCountry']);
        }

        $tester = new CommandTester(new SyncIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [new OrderCountryEnricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertArrayHasKey('orderCountry', $this->gateway->indices['audit_log']['mappings']['properties']);
        self::assertArrayNotHasKey('orderCountry', $this->gateway->indices['audit_auth_log']['mappings']['properties'], 'sync added a field to an index whose records cannot carry it');
        self::assertArrayNotHasKey('orderCountry', $this->gateway->indices['audit_stock_log']['mappings']['properties']);
    }

    public function testTheDumpNamesTheIndexEachDefinitionIsFor(): void
    {
        $tester = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), [new OrderCountryEnricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dump' => true]));

        $dumped = json_decode($tester->getDisplay(), true);

        self::assertIsArray($dumped);
        self::assertSame(['audit_log', 'audit_auth_log', 'audit_stock_log'], array_keys($dumped));
        self::assertArrayHasKey('orderCountry', $dumped['audit_log']['mappings']['properties']);
        self::assertArrayNotHasKey('orderCountry', $dumped['audit_auth_log']['mappings']['properties']);
    }

    public function testOneIndexStillDumpsOneDefinition(): void
    {
        // Nothing to tell apart, so nothing is keyed: what --dump printed before, for
        // the configuration most applications have.
        $tester = new CommandTester(new CreateIndexCommand($this->gateway, new IndexResolver('audit_log'), new IndexDefinition(), [new OrderCountryEnricher()]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dump' => true]));

        $dumped = json_decode($tester->getDisplay(), true);

        self::assertIsArray($dumped);
        self::assertArrayHasKey('mappings', $dumped, 'the dump was keyed by index name for a configuration with one index');
    }

    public function testTheWriterReadsTheSameDeclaration(): void
    {
        // The declaration is one answer, not two. An enricher whose supports() says yes
        // to everything but which named `order` must not add its field to an auth
        // record — that record goes to an index whose mapping was told the field would
        // never be there, and under dynamic: false it would be stored and unsearchable.
        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, $this->resolver, new ChainActorResolver([], 'tests'), new FrozenClock(), [new OrderCountryEnricher()], FailurePolicy::Throw);

        $writer->record('order', 1, 'update', ['status' => new Change('new', 'paid')]);
        $writer->record('auth', 2, 'login');

        self::assertSame('UA', $this->gateway->documents['audit_log'][0]['orderCountry'] ?? null);
        self::assertArrayNotHasKey('orderCountry', $this->gateway->documents['audit_auth_log'][0]);
    }

    public function testAnIndexThatAlreadyExistsDoesNotStopTheOnesAfterItFromBeingCreated(): void
    {
        // The loop skips what is already there. Leaving the loop instead of skipping one
        // turn means a deployment that added a routed index to an existing installation
        // creates nothing at all — and the first write into the new index is what
        // discovers it, by having Elasticsearch invent a mapping.
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), []));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertArrayHasKey('audit_auth_log', $this->gateway->indices, 'the index after the existing one was never created');
        self::assertArrayHasKey('audit_stock_log', $this->gateway->indices);
    }

    public function testAMissingFieldDoesNotHideTheFieldsAfterIt(): void
    {
        // The comparison walks every expected field and collects what is wrong with each.
        // Stopping at the first missing one turns "three fields are missing" into one,
        // and audit:index:sync then adds one field per run for as many runs as it takes.
        $this->createEveryIndex();
        unset(
            $this->gateway->indices['audit_log']['mappings']['properties']['objectId'],
            $this->gateway->indices['audit_log']['mappings']['properties']['event'],
        );

        $tester = new CommandTester(new CheckCommand($this->gateway, $this->resolver, new IndexDefinition()));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('objectId', $display);
        self::assertStringContainsString('event', $display, 'only the first missing field was named');
    }

    public function testSyncAddsEveryMissingFieldInOnePass(): void
    {
        // The same list, one step later: the fields are folded into a single partial
        // mapping and sent once. Folding only the last one leaves the rest for a second
        // run nobody knows to make.
        $this->createEveryIndex();
        unset(
            $this->gateway->indices['audit_log']['mappings']['properties']['objectId'],
            $this->gateway->indices['audit_log']['mappings']['properties']['event'],
        );

        $tester = new CommandTester(new SyncIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), []));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $properties = $this->gateway->indices['audit_log']['mappings']['properties'];

        self::assertArrayHasKey('objectId', $properties);
        self::assertArrayHasKey('event', $properties, 'one of the missing fields was not added');
    }

    public function testSyncSaysWhichIndexIsMissingRatherThanTryingToMapIt(): void
    {
        $this->gateway->indices['audit_log'] = (new IndexDefinition())->toArray();

        $tester = new CommandTester(new SyncIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), []));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(1, substr_count($tester->getDisplay(), 'audit_auth_log'), 'the missing index was reported twice');
    }

    public function testCreateSaysWhatTheClusterSaidAndSaysItOnce(): void
    {
        // The command an operator runs first, so its error is the first thing they read
        // about this bundle. A cause chain restating itself turns one refusal into three
        // lines that look like three problems.
        $this->gateway->failWith = new \RuntimeException('no permission to create indices', 0, new \RuntimeException('no permission to create indices'));

        $tester = new CommandTester(new CreateIndexCommand($this->gateway, $this->resolver, new IndexDefinition(), []));

        self::assertSame(Command::FAILURE, $tester->execute([]));

        // Whitespace normalised first: the console wraps to whatever width it is given,
        // and a test that counts a phrase would otherwise be asserting the terminal.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertStringContainsString('no permission to create indices', $display);
        self::assertSame(3, substr_count($display, 'no permission to create indices'), 'once per index it could not reach, and no more');
    }

    /**
     * The world the check is asked about, built by hand rather than by the command
     * that is under test here too. Built with it, these tests could not fail: a create
     * that put orderCountry everywhere would leave a check that finds nothing missing
     * everywhere, and both halves of the bug would agree with each other.
     */
    private function createEveryIndex(): void
    {
        foreach (['audit_log', 'audit_auth_log', 'audit_stock_log'] as $index) {
            $this->gateway->indices[$index] = (new IndexDefinition())->toArray();
        }

        $this->gateway->indices['audit_log']['mappings']['properties']['orderCountry'] = ['type' => 'keyword'];
    }
}

final class OrderCountryEnricher implements ScopedEnricherInterface
{
    public function objectTypes(): array
    {
        return ['order'];
    }

    public function supports(AuditRecord $record): bool
    {
        // Deliberately unconditional: the object type is the interface's answer, and a
        // supports() that repeats it would hide whether the declaration is being read.
        return true;
    }

    public function enrich(AuditRecord $record): AuditRecord
    {
        return $record->withAttributes(['orderCountry' => 'UA']);
    }

    public function mapping(): array
    {
        return ['orderCountry' => ['type' => 'keyword']];
    }
}

final class EveryTypeEnricher implements AuditEnricherInterface
{
    public function supports(AuditRecord $record): bool
    {
        return true;
    }

    public function enrich(AuditRecord $record): AuditRecord
    {
        return $record->withAttributes(['tenant' => 'acme']);
    }

    public function mapping(): array
    {
        return ['tenant' => ['type' => 'keyword']];
    }
}
