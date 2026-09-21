<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MomentEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Borsche\ElasticsearchAuditBundle\Writer\Provenance;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An enricher that is asked about the moment instead of about the record.
 *
 * The rest of the enricher machinery runs when a record is written, which is the same
 * instant the change happened for every record but the one this exists for: a flush
 * whose publishing was swallowed is written by the next flush, and by then the request
 * an enricher would read is somebody else's. So this kind is asked once, before the
 * records of the moment exist, and its answer travels with them.
 *
 * What that costs is written down here as much as what it buys — the value is not
 * overwritten afterwards, and an ordinary enricher that tries is told, because the
 * alternative is the later request's route quietly winning and looking right.
 */
final class WhatAMomentEnricherDescribesTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->logs = [];
        $this->gateway = new InMemoryGateway();
    }

    public function testItsFieldsAreOnTheRecord(): void
    {
        $this->writer([self::describing(['route' => '/checkout'])])->record('order', 1, 'updated');

        self::assertSame('/checkout', $this->gateway->only('audit_log')['route'] ?? null);
    }

    public function testItIsAskedOncePerMomentRatherThanOncePerRecord(): void
    {
        // The reason it has no AuditRecord parameter, stated as a count: a batch is one
        // moment, and an enricher reading the request has no business being asked about
        // it five hundred times.
        $counting = new class implements MomentEnricherInterface {
            public int $asked = 0;

            public function describe(): array
            {
                ++$this->asked;

                return ['route' => '/checkout'];
            }

            public function mapping(): array
            {
                return ['route' => ['type' => 'keyword']];
            }
        };

        $writer = $this->writer([$counting]);

        $writer->writeAll([
            new AuditRecord('order', 1, 'updated'),
            new AuditRecord('order', 2, 'updated'),
            new AuditRecord('order', 3, 'updated'),
        ], $writer->provenance());

        self::assertCount(3, $this->gateway->documents['audit_log'] ?? []);
        self::assertSame(['/checkout', '/checkout', '/checkout'], array_column($this->gateway->documents['audit_log'], 'route'));
        self::assertSame(1, $counting->asked, 'the moment was described once per record of the batch');
    }

    public function testAnOrdinaryEnricherDoesNotGetToOverwriteIt(): void
    {
        // The whole point, in one assertion. An ordinary enricher runs when the record
        // is written, so letting it win would put the writing moment's value back under
        // a name that promises the other one.
        $this->writer([
            self::describing(['route' => '/checkout']),
            self::enriching(['route' => '/whatever-is-running-now']),
        ])->record('order', 1, 'updated');

        self::assertSame('/checkout', $this->gateway->only('audit_log')['route'] ?? null);
    }

    public function testAndIsToldSoWithBothValues(): void
    {
        // Silently discarding it would leave an application with two enrichers, one of
        // which does nothing, and no way to find out which. Both values are in the
        // message because choosing between them is the application's decision.
        $this->writer([
            self::describing(['route' => '/checkout']),
            self::enriching(['route' => '/whatever-is-running-now']),
        ])->record('order', 1, 'updated');

        $said = $this->logs[0]['message'] ?? '';

        self::assertStringContainsString('route', $said, 'nothing was logged about the discarded value');
        self::assertSame('/checkout', $this->logs[0]['context']['kept'] ?? null);
        self::assertSame('/whatever-is-running-now', $this->logs[0]['context']['discarded'] ?? null);
    }

    public function testAnAttributeTheCallerSetWinsAndIsNotDefended(): void
    {
        // The moment fills in what is missing; it does not overrule somebody who said
        // what they wanted on this particular record. And a key it did not win is not
        // one it gets to keep afterwards — otherwise "the moment owns it" would depend
        // on an enricher nobody can see having set it first.
        $this->writer([
            self::describing(['route' => '/checkout']),
            self::enriching(['route' => '/ordinary-enricher-won']),
        ])->record('order', 1, 'updated', [], ['route' => '/the-caller-said-so']);

        self::assertSame('/ordinary-enricher-won', $this->gateway->only('audit_log')['route'] ?? null);
        self::assertSame([], $this->logs, 'the moment defended an attribute it never set');
    }

    public function testOneThatFailsCostsItsOwnFieldsAndNothingElse(): void
    {
        // There is no record to report against — this runs before the records exist —
        // and a flush must not lose its history because a request lookup did not work.
        $broken = new class implements MomentEnricherInterface {
            public function describe(): array
            {
                throw new \RuntimeException('no request here');
            }

            public function mapping(): array
            {
                return ['route' => ['type' => 'keyword']];
            }
        };

        $this->writer([$broken, self::describing(['tenant' => 'north'])])->record('order', 1, 'updated');

        $document = $this->gateway->only('audit_log');

        self::assertSame('north', $document['tenant'] ?? null, 'one failing moment enricher took the others with it');
        self::assertArrayNotHasKey('route', $document);
        // The message is a PSR-3 template; what it says is in the context beside it.
        self::assertSame('no request here', $this->logs[0]['context']['reason'] ?? null);
        self::assertStringContainsString('{reason}', $this->logs[0]['message'] ?? '');
    }

    public function testTheMomentTravelsWithARecordWrittenLaterThanItHappened(): void
    {
        // The writer's half of the Doctrine case: a provenance describes a moment that
        // has passed, and the records completed against it carry that moment rather than
        // whatever the enricher would say now.
        $moving = new class implements MomentEnricherInterface {
            public string $route = '/checkout';

            public function describe(): array
            {
                return ['route' => $this->route];
            }

            public function mapping(): array
            {
                return ['route' => ['type' => 'keyword']];
            }
        };

        $writer = $this->writer([$moving]);
        $provenance = $writer->provenance();

        $moving->route = '/a-completely-different-request';

        $writer->writeAll([new AuditRecord('order', 1, 'updated')], $provenance);

        self::assertSame('/checkout', $this->gateway->only('audit_log')['route'] ?? null);
    }

    public function testAProvenanceCarriesWhatTheMomentSaid(): void
    {
        self::assertSame(['route' => '/checkout'], $this->writer([self::describing(['route' => '/checkout'])])->provenance()->attributes);
        self::assertSame([], (new Provenance(new \DateTimeImmutable(), null))->attributes, 'a provenance built without a moment still has to have one');
    }

    public function testItsMappingIsFoldedIntoTheIndexLikeAnyOthers(): void
    {
        // The claim behind sharing one tag: everything that folds enricher fields into
        // an index asks the one question both kinds answer, so a moment enricher's
        // field is mapped without audit:index:create knowing what kind it is.
        $tester = new CommandTester(new CreateIndexCommand(
            $gateway = new InMemoryGateway(),
            new IndexResolver('audit_log'),
            new IndexDefinition(),
            [self::describing(['route' => '/checkout'])],
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['type' => 'keyword'], $gateway->indices['audit_log']['mappings']['properties']['route'] ?? null);
    }

    /**
     * A moment enricher that always says the same thing.
     *
     * @param array<string, mixed> $moment
     */
    private static function describing(array $moment): MomentEnricherInterface
    {
        return new class($moment) implements MomentEnricherInterface {
            /** @param array<string, mixed> $moment */
            public function __construct(private readonly array $moment)
            {
            }

            public function describe(): array
            {
                return $this->moment;
            }

            public function mapping(): array
            {
                return array_map(static fn (): array => ['type' => 'keyword'], $this->moment);
            }
        };
    }

    /**
     * An ordinary enricher that always adds the same attributes.
     *
     * @param array<string, mixed> $attributes
     */
    private static function enriching(array $attributes): AuditEnricherInterface
    {
        return new class($attributes) implements AuditEnricherInterface {
            /** @param array<string, mixed> $attributes */
            public function __construct(private readonly array $attributes)
            {
            }

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes($this->attributes);
            }

            public function mapping(): array
            {
                return array_map(static fn (): array => ['type' => 'keyword'], $this->attributes);
            }
        };
    }

    /**
     * @param list<AuditEnricherInterface|MomentEnricherInterface> $enrichers
     */
    private function writer(array $enrichers): AuditWriter
    {
        $transport = new SyncTransport($this->gateway, new FrozenClock());

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), $enrichers, logger: $this->logger());
    }

    private function logger(): AbstractLogger
    {
        return new class($this->logs) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
