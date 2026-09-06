<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MergedRecordEnricherInterface;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\DoctrineSupport;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * When the list of enrichers is read, and why the answer is "later".
 *
 * A tagged iterator is lazy on purpose: it holds the ids and builds each service when
 * the iteration reaches it. Reading it in a constructor takes that away, and what it
 * takes it away from is the case where an enricher depends on the writer it enriches
 * for — a listener that collects changes and records them, which is an ordinary thing
 * to write. Then building the writer builds the enricher, which asks for the writer,
 * which builds the enricher: a compiled container recurses until the stack is gone,
 * with no exception and no trace, and the application dies inside cache:warmup.
 *
 * 1.1.0 read them in the constructor, so the tests below are the shape of that bug.
 * They also pin what the eager read was there for — a list walked once and no more —
 * because a repair that lost that would go quietly wrong instead of loudly.
 */
final class EnrichersAreBuiltWhenNeededTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testBuildingTheWriterBuildsNoEnrichers(): void
    {
        $walked = false;
        $consulted = 0;
        $writer = $this->writer((static function () use (&$walked, &$consulted): \Generator {
            $walked = true;

            yield new CountingEnricher($consulted);
        })());

        self::assertFalse($walked, 'the writer read its enrichers while it was being constructed');

        $writer->record('order', 1, 'update', ['status' => new Change('new', 'paid')]);

        self::assertTrue($walked, 'and then never read them at all');
    }

    public function testAGeneratorOfEnrichersIsNotExhaustedAfterTheFirstRecord(): void
    {
        // The reason the list is read once rather than per record. The signature says
        // iterable because a tagged iterator is one, which makes a plain Generator legal
        // too — and it is walked twice for every record, since complete() and prepare()
        // take different halves of it. A second walk of a Generator finds nothing, and
        // an enricher that stops being consulted does not fail: the records quietly lose
        // the attribute the application filters its history by.
        $consulted = 0;
        $writer = $this->writer((static function () use (&$consulted): \Generator {
            yield new CountingEnricher($consulted);
        })());

        $writer->record('order', 1, 'update', ['status' => new Change('new', 'paid')]);
        $writer->record('order', 2, 'update', ['status' => new Change('new', 'paid')]);

        self::assertSame(2, $consulted, 'the second record was written without asking the enricher');
        self::assertSame(['acme', 'acme'], array_column($this->gateway->documents['audit_log'], 'tenant'));
    }

    public function testTheSecondWalkOfOneRecordStillFindsTheMergedEnrichers(): void
    {
        // The narrow case the test above cannot see. One record is walked twice, not
        // once: complete() takes the ordinary enrichers and prepare() takes the merged
        // ones, and prepare() is the walk that would come back empty if the list were
        // read afresh each time instead of remembered. Nothing fails when it does — the
        // record is written, it is simply written without the attribute the application
        // filters its history by, which is the quiet kind of wrong.
        $writer = $this->writer((static function (): \Generator {
            yield new MergedOnlyEnricher();
        })());

        $writer->record('order', 1, 'update', ['status' => new Change('new', 'paid')]);

        self::assertSame(['acme'], array_column($this->gateway->documents['audit_log'], 'tenant'));
    }

    public function testBuildingTheComparatorChainBuildsNoComparators(): void
    {
        // Same shape, same reasons: a comparator is as entitled to depend on something
        // that leads back to the chain — an EntityManager whose listeners audit — and
        // the chain is walked again for every value compared.
        $walked = false;
        $chain = new ValueComparator((static function () use (&$walked): \Generator {
            $walked = true;

            yield new NumericNullAsZeroComparator(['stock.fact']);
        })());

        self::assertFalse($walked, 'the chain read its comparators while it was being constructed');

        self::assertTrue($chain->equals('stock', 'fact', null, 0));

        self::assertTrue($walked, 'and then never read them at all');
    }

    // In its own process on purpose. A regression here does not fail, it ends the
    // process - the stack runs out, and PHP has nothing left to report with. Isolated,
    // that costs one test instead of the whole run, and the report still names it.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnEnricherMayDependOnTheWriterItEnrichesFor(): void
    {
        // The field case, through a container rather than a hand-made generator: the
        // enricher is tagged, it is handed the writer, and asking for the writer has to
        // be answerable. Under the 1.1.0 constructor this is a circular reference in a
        // ContainerBuilder and an infinite recursion in the compiled container an
        // application actually runs — the same defect, told twice as loudly here.
        $container = new ContainerBuilder();
        (new ElasticsearchAuditExtension(doctrine: DoctrineSupport::none()))
            ->load([['client' => ['hosts' => ['http://localhost:9200']]]], $container);

        $container->setDefinition(ElasticsearchAuditExtension::SERVICE_GATEWAY, new Definition(InMemoryGateway::class));
        $container->setDefinition('test.enricher', (new Definition(WriterDependentEnricher::class, [
            new Reference(ElasticsearchAuditExtension::SERVICE_WRITER),
        ]))->addTag(ElasticsearchAuditExtension::TAG_ENRICHER)->setPublic(true));
        $container->getDefinition(ElasticsearchAuditExtension::SERVICE_WRITER)->setPublic(true);

        $container->compile();

        $writer = $container->get(ElasticsearchAuditExtension::SERVICE_WRITER);

        self::assertInstanceOf(AuditWriter::class, $writer);

        $enricher = $container->get('test.enricher');

        self::assertInstanceOf(WriterDependentEnricher::class, $enricher);
        self::assertSame($writer, $enricher->writer, 'and it is the same writer, not a second one built to break the cycle');
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    private function writer(iterable $enrichers): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), $enrichers, FailurePolicy::Throw);
    }
}

final class CountingEnricher implements AuditEnricherInterface
{
    /**
     * @param int $consulted counted by reference, so a test can see the walks that were
     *                       not made as well as the ones that were
     */
    public function __construct(private int &$consulted)
    {
    }

    public function supports(AuditRecord $record): bool
    {
        ++$this->consulted;

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

final class WriterDependentEnricher implements AuditEnricherInterface
{
    public function __construct(public readonly AuditWriter $writer)
    {
    }

    public function supports(AuditRecord $record): bool
    {
        return false;
    }

    public function enrich(AuditRecord $record): AuditRecord
    {
        return $record;
    }

    public function mapping(): array
    {
        return [];
    }
}

final class MergedOnlyEnricher implements MergedRecordEnricherInterface
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
