<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Command\CreateIndexCommand;
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MomentEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ScopedEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailureDetails;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
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

    public function testABatchNobodyHandedAProvenanceIsStillOneMoment(): void
    {
        // The test above passes a provenance, so it only pins the listener's road. An
        // application writing its own batch does not have one and does not know the
        // argument exists — and the promise is about batches, not about who assembled
        // them. Every record asking the clock, the actor resolver and every moment
        // enricher again is exactly what "never per record of a batch" rules out.
        $counting = new class implements MomentEnricherInterface {
            public int $asked = 0;

            public function describe(): array
            {
                return ['sequence' => ++$this->asked];
            }

            public function mapping(): array
            {
                return ['sequence' => ['type' => 'integer']];
            }
        };

        $this->writer([$counting])->writeAll([
            new AuditRecord('order', 1, 'update'),
            new AuditRecord('order', 2, 'update'),
            new AuditRecord('order', 3, 'update'),
        ]);

        self::assertSame(1, $counting->asked, 'a batch written without a provenance asked for the moment once per record');
        self::assertSame([1, 1, 1], array_column($this->gateway->documents['audit_log'], 'sequence'));
    }

    public function testSettlingTheMomentForABatchStaysBehindTheFailurePolicy(): void
    {
        // The moment is settled for the batch, which means the clock and the actor
        // resolver are asked before any record is looked at — and that is inside the
        // guard, not before it. A resolver that throws used to come out of this method
        // raw, past on_failure entirely, and take with it a record that needed nothing
        // from it.
        $angry = new class implements ActorResolverInterface {
            public function resolve(): ?string
            {
                throw new \RuntimeException('the security token store is not configured here');
            }
        };

        $transport = new SyncTransport($this->gateway, new FrozenClock());
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), $angry, new FrozenClock(), [], FailurePolicy::Log, $this->logger());

        $writer->writeAll([(new AuditRecord('order', 1, 'update', new \DateTimeImmutable('2026-09-21 10:00:00'), 'alice'))->withId('rec-1')]);

        self::assertCount(1, $this->gateway->documents['audit_log'] ?? [], 'a record that needed nothing from the resolver was lost to it');
        self::assertSame('alice', $this->gateway->only('audit_log')['source'] ?? null, 'and what it did carry was thrown away');
        self::assertNotSame([], $this->logs, 'the failure went unreported');
    }

    public function testAnEmptyBatchAsksNobodyAnything(): void
    {
        $asked = 0;
        $counting = new class($asked) implements ActorResolverInterface {
            public function __construct(private int &$asked)
            {
            }

            public function resolve(): ?string
            {
                ++$this->asked;

                return 'alice';
            }
        };

        $transport = new SyncTransport($this->gateway, new FrozenClock());
        (new AuditWriter($transport, $transport, new IndexResolver('audit_log'), $counting, new FrozenClock()))->writeAll([]);

        self::assertSame(0, $asked, 'a batch with nothing in it settled a moment for nobody');
    }

    public function testOneThatClaimsAScopeIsRefusedRatherThanHalfHonoured(): void
    {
        // The two disagree: a moment is the same one for every record of it, so there is
        // no record for objectTypes() to narrow — but the index commands honoured the
        // declaration and mapped its fields into some indices, while the writer put the
        // values on every record. Under dynamic: false that is a field stored and
        // unsearchable, in an index the application was told it would never be in.
        $both = new class implements MomentEnricherInterface, ScopedEnricherInterface {
            public function objectTypes(): array
            {
                return ['order'];
            }

            public function describe(): array
            {
                return ['route' => '/checkout'];
            }

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
                return ['route' => ['type' => 'keyword']];
            }
        };

        // Raised past the failure policy on purpose: on_failure is about a cluster
        // having a bad day, not about a bundle assembled wrong.
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('there is no record for objectTypes() to narrow');

        $this->writer([$both])->record('order', 1, 'update');
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

    public function testAndIsToldSoWithoutEitherValue(): void
    {
        // Silently discarding it would leave an application with two enrichers, one of
        // which does nothing, and no way to find out which. Which attribute and which
        // enricher is enough to find it; neither value is here, because this runs before
        // redaction and a value that a rule covers — or one nested inside an attribute no
        // rule names — would be in the log while the document had a placeholder.
        $ordinary = self::enriching(['route' => '/whatever-is-running-now']);

        $this->writer([self::describing(['route' => '/checkout']), $ordinary])->record('order', 1, 'updated');

        $said = $this->logs[0]['message'] ?? '';

        self::assertStringContainsString('route', $said, 'nothing was logged about the collision');
        self::assertStringContainsString($ordinary::class, $said, 'the message does not say which enricher did it');
        self::assertSame('route', $this->logs[0]['context']['attribute'] ?? null);

        $context = json_encode($this->logs[0]['context'] ?? [], \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('/checkout', $context, 'the value the moment described is in the log');
        self::assertStringNotContainsString('/whatever-is-running-now', $context, 'and so is the one that was discarded');
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

    public function testASecretNestedInsideAnAttributeIsNotInTheLogEither(): void
    {
        // The first attempt at this withheld the values when a redaction rule named the
        // attribute — and a rule names a field while redaction walks into the values, so
        // a secret one level down, under a key no rule mentions, went into the log while
        // the document had a placeholder in its place. Asking the redactor about the top
        // key was a second implementation of the redaction rules standing next to the
        // first and disagreeing with it.
        $this->writer([
            self::describing(['context' => ['password' => 'ALICE_SECRET_7c1f']]),
            self::enriching(['context' => ['password' => 'BOB_SECRET_9a2e']]),
        ], new ChangeRedactor(['password']))->record('user', 7, 'update');

        $said = json_encode($this->logs, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('ALICE_SECRET_7c1f', $said, 'a secret nested inside the attribute reached the log');
        self::assertStringNotContainsString('BOB_SECRET_9a2e', $said, 'and so did the one that was discarded');

        // Still reported, and still actionable: which attribute and which enricher.
        self::assertStringContainsString('context', $this->logs[0]['message'] ?? '');
        self::assertSame('context', $this->logs[0]['context']['attribute'] ?? null);

        $document = json_encode($this->gateway->only('audit_log'), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('ALICE_SECRET_7c1f', $document, 'the premise: the document itself is redacted');
    }

    public function testEveryAttributeTakenOverIsReportedNotJustTheFirst(): void
    {
        // Two attributes taken at once, and both of them named: an application with two
        // enrichers needs to know about every field they disagree on, not the first.
        $this->writer([
            self::describing(['token' => 'ALICE_SECRET_7c1f', 'route' => '/checkout']),
            self::enriching(['token' => 'BOB_SECRET_9a2e', 'route' => '/whatever-is-running-now']),
        ], new ChangeRedactor(['token']))->record('user', 7, 'update');

        self::assertCount(2, $this->logs, 'only one of the two collisions was reported');
        self::assertSame(['token', 'route'], array_column(array_column($this->logs, 'context'), 'attribute'));
    }

    public function testAFailingMomentEnricherDoesNotRepeatItsOwnException(): void
    {
        // An enricher asked to read the request is as likely to quote a token in its
        // exception as a cluster is to quote a document in its error, and this bundle
        // has one policy for repeating what other people's code said. The new log line
        // was going around it.
        $broken = new class implements MomentEnricherInterface {
            public function describe(): array
            {
                throw new \RuntimeException('Authorization: Bearer ALICE_SECRET_7c1f');
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $this->writer([$broken])->record('user', 7, 'update');

        $said = json_encode($this->logs, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('ALICE_SECRET_7c1f', $said, 'the enricher own message travelled into the log under the default policy');
        self::assertNotSame([], $this->logs, 'and the failure went unreported entirely');
    }

    public function testUnderFullDetailsTheEnrichersOwnMessageIsWhatWasAskedFor(): void
    {
        // The other half of the setting: "full" means repeat what other code said, and
        // an installation that asked for it gets it here like everywhere else.
        $broken = new class implements MomentEnricherInterface {
            public function describe(): array
            {
                throw new \RuntimeException('the request stack was empty');
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $this->writer([$broken], null, FailureDetails::Full)->record('user', 7, 'update');

        self::assertSame('the request stack was empty', $this->logs[0]['context']['reason'] ?? null);
    }

    public function testAnEnricherThatLeavesTheMomentAloneIsNotAccusedOfAnything(): void
    {
        // The other side of the rule. "Kept" is only interesting when somebody tried to
        // take it: an enricher adding a field of its own beside the moment's is the
        // ordinary case, and telling an application about it every record would make the
        // message worth ignoring by the time it matters.
        $this->writer([
            self::describing(['route' => '/checkout']),
            self::enriching(['tenant' => 'north']),
        ])->record('order', 1, 'updated');

        $document = $this->gateway->only('audit_log');

        self::assertSame('/checkout', $document['route'] ?? null);
        self::assertSame('north', $document['tenant'] ?? null);
        self::assertSame([], $this->logs, 'an enricher that touched nothing of the moment was reported anyway');
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
        // The message is a PSR-3 template; what it says is in the context beside it. The
        // enricher's own sentence is not in there under the default policy — that is
        // what the two tests above are about — but which enricher failed is, because
        // that is the part the application acts on.
        self::assertSame($broken::class, $this->logs[0]['context']['enricher'] ?? null, 'the log does not say which enricher failed');
        self::assertStringContainsString('RuntimeException', (string) ($this->logs[0]['context']['reason'] ?? ''));
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
    private function writer(array $enrichers, ?ChangeRedactor $redactor = null, ?FailureDetails $details = null): AuditWriter
    {
        $transport = new SyncTransport($this->gateway, new FrozenClock());

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), $enrichers, logger: $this->logger(), redactor: $redactor, failureDetails: $details);
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
