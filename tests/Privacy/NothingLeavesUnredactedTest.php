<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A redacted value must not exist anywhere an application can see it — not in the
 * document, not in an event, not in an exception, not in a log line. Asserting field
 * by field misses whatever is added next; this looks at everything that came out.
 */
final class NothingLeavesUnredactedTest extends TestCase
{
    private const SECRET = 'hunter2-the-actual-password';

    public function testAnAttributeIsNotAWayPastRedaction(): void
    {
        // The channel redaction did not cover is the indexed one: `changes` is stored
        // and not searchable, an attribute is searchable.
        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['password']);

        $writer->record('user', 7, 'update', ['name' => new Change('a', 'b')], ['password' => self::SECRET, 'tenant' => 'acme']);

        $document = $gateway->documents['audit_log'][0];

        self::assertArrayNotHasKey('password', $document, 'a redacted attribute is not written at all');
        self::assertSame('acme', $document['tenant']);
    }

    public function testATypedAttributeIsNotMaskedIntoSomethingTheMappingRefuses(): void
    {
        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['salesType']);

        $writer->record('order', 1, 'update', ['status' => new Change('a', 'b')], ['salesType' => 2]);

        self::assertArrayNotHasKey('salesType', $gateway->documents['audit_log'][0], "an integer field masked with '***' would make Elasticsearch refuse the document");
    }

    public function testAListenerCannotHandBackWhatWasRemoved(): void
    {
        $gateway = new InMemoryGateway();
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof RecordCreatedEvent) {
                    // A listener that means well and reaches for the entity again.
                    $event->setRecord($event->getRecord()->withChanges(['password' => new Change(null, NothingLeavesUnredactedTest::secret())]));
                }

                return $event;
            }
        };

        $writer = $this->writer($gateway, ['password'], $events);
        $writer->record('user', 7, 'update', ['password' => new Change(null, self::SECRET)]);

        self::assertStringNotContainsString(self::SECRET, json_encode($gateway->documents, JSON_THROW_ON_ERROR));
    }

    public function testTheSecretIsInNothingThatCameOut(): void
    {
        $gateway = new InMemoryGateway();
        $gateway->failWith = new \RuntimeException('the cluster said no');

        $seen = [];
        $events = new class($seen) implements EventDispatcherInterface {
            /** @param list<object> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof RecordCreatedEvent || $event instanceof RecordFailedEvent) {
                    $this->seen[] = $event;
                }

                return $event;
            }
        };

        $logs = [];
        $writer = $this->writer($gateway, ['password'], $events, $logs, FailurePolicy::Log);

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::SECRET)], ['password' => self::SECRET]);

        $observable = json_encode([
            'documents' => $gateway->documents,
            'events' => array_map(static fn (object $e): array => (array) $e, $seen),
            'logs' => $logs,
        ], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);

        self::assertStringNotContainsString(self::SECRET, $observable);
    }

    public function testTheSecretEntersByEveryDoorAndLeavesByNone(): void
    {
        // The canary. One secret, pushed in through every door the writer has —
        // both sides of a change, a free-form change, an attribute, a scoped field —
        // and then looked for in everything an application can observe. Its value is
        // that a channel added in six months' time makes it fail without anyone having
        // to remember this file exists.
        $gateway = new InMemoryGateway();

        $seen = [];
        $events = new class($seen) implements EventDispatcherInterface {
            /** @param list<object> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                $this->seen[] = $event;

                return $event;
            }
        };

        $logs = [];
        $writer = $this->writer($gateway, ['password', 'secret', 'user.token'], $events, $logs);

        $writer->record('user', 7, 'update', [
            'password' => new Change(self::SECRET, self::SECRET),
            'token' => new Change(null, self::SECRET),
            'secret' => self::SECRET,                       // free-form, not a Change
            'lines.42.password' => new Change(null, self::SECRET),
            'name' => new Change('a', 'b'),
        ], [
            'password' => self::SECRET,
            'tenant' => 'acme',
        ]);

        $observable = json_encode([
            'documents' => $gateway->documents,
            'events' => array_map(static fn (object $e): array => (array) $e, $seen),
            'logs' => $logs,
        ], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);

        self::assertStringNotContainsString(self::SECRET, $observable);
        self::assertSame('acme', $gateway->documents['audit_log'][0]['tenant'], 'and everything else came through');
        self::assertSame(['old' => 'a', 'new' => 'b'], $gateway->documents['audit_log'][0]['changes']['name']);
    }

    public function testTheDefaultPolicyDoesNotWriteSomebodyElsesExceptionIntoTheLog(): void
    {
        // The failure path was outside the privacy boundary entirely: the record was
        // redacted and then the cause's message — the cluster's, an enricher's, anyone's
        // — went into the log line and the exception itself into the PSR-3 context,
        // where a processor serialises it. Under the default "log" policy, which is the
        // one most applications run.
        $gateway = new InMemoryGateway();
        $gateway->failWith = new \RuntimeException('rejected value '.self::SECRET);

        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Log);

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::SECRET)]);

        self::assertNotSame([], $logs, 'the failure was logged at all');
        self::assertStringNotContainsString(self::SECRET, implode("\n", $logs));
        self::assertStringContainsString('TransportUnavailableException', implode("\n", $logs), 'and the cause is still named by class');
    }

    public function testAListenerOnTheFailureEventCannotReadTheSecretEither(): void
    {
        // RecordFailedEvent is an application-visible channel — its own docblock
        // suggests alerting on it — and it handed listeners the raw Throwable.
        $gateway = new InMemoryGateway();
        $gateway->failWith = new \RuntimeException('rejected value '.self::SECRET);

        $reason = null;
        $events = new class($reason) implements EventDispatcherInterface {
            public function __construct(private ?string &$reason)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof RecordFailedEvent) {
                    $this->reason = $event->reason->getMessage().'|'.($event->reason->getPrevious()?->getMessage() ?? '');
                }

                return $event;
            }
        };

        $logs = [];
        $writer = $this->writer($gateway, ['password'], $events, $logs, FailurePolicy::Log);
        $writer->record('user', 7, 'update', ['password' => new Change(null, self::SECRET)]);

        self::assertNotNull($reason, 'the event was dispatched');
        self::assertStringNotContainsString(self::SECRET, (string) $reason);
    }

    public function testAnEnrichersOwnExceptionIsNotTrustedEither(): void
    {
        // The heuristic this replaces: "no previous exception, therefore the bundle
        // wrote the message". An application's enricher throws directly, with no
        // previous, and its message is the application's — which is exactly where a
        // value it was enriching from would be.
        $gateway = new InMemoryGateway();

        $enricher = new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                throw new \RuntimeException('cannot enrich with token '.NothingLeavesUnredactedTest::secret());
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Throw, [$enricher]);

        try {
            $writer->record('user', 7, 'update', ['password' => new Change(null, self::SECRET)]);
            self::fail('the write should have failed');
        } catch (WriteFailedException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }

        self::assertStringNotContainsString(self::SECRET, implode("\n", $logs));
    }

    public function testTheBundlesOwnWordsAboutADeclarationAreStillSaidInFull(): void
    {
        // The other half: a declaration mistake is the bundle's own sentence, built
        // from class and field names, and losing it would make a common misconfiguration
        // unreadable. It says so about itself rather than being guessed at.
        $gateway = new InMemoryGateway();
        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Throw);

        try {
            $writer->write((new AuditRecord('user', 7, 'update'))->withChanges(['x' => new Change(1, 2)]));
            $writer->reportFailure(new \Borsche\ElasticsearchAuditBundle\Exception\DeclarationMistake('"nope" is listed as always recorded but is not an audited field.'), new AuditRecord('user', 7, 'update'));
            self::fail('expected a failure');
        } catch (WriteFailedException $e) {
            self::assertStringContainsString('"nope" is listed as always recorded', $e->getMessage());
        }
    }

    public function testAnExceptionCarryingTheSecretIsWhereTheGuaranteeEnds(): void
    {
        // Adversarial, because the invariant above proves less than it sounds: the
        // failures it stages never carry the secret in the first place. Here the
        // cluster's own exception does — the message of an exception the bundle did
        // not write and must not rewrite, because it is the only diagnostic left.
        // The bundle's own reach: its document, its events, its log lines, its
        // exception message.
        $gateway = new InMemoryGateway();
        $gateway->failWith = new \RuntimeException('rejected value '.self::SECRET);

        $seen = [];
        $events = new class($seen) implements EventDispatcherInterface {
            /** @param list<object> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof RecordCreatedEvent || $event instanceof RecordFailedEvent) {
                    $this->seen[] = $event;
                }

                return $event;
            }
        };

        $logs = [];
        $writer = $this->writer($gateway, ['password'], $events, $logs, FailurePolicy::Throw);

        try {
            $writer->record('user', 7, 'update', ['password' => new Change(null, self::SECRET)]);
            self::fail('the write should have failed');
        } catch (WriteFailedException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage(), 'what the bundle writes never carries the value');
            self::assertStringNotContainsString(self::SECRET, json_encode($gateway->documents, JSON_THROW_ON_ERROR));

            // And what it cannot scrub, stated rather than pretended away: the third
            // party's own message travels as `previous`, which is where the README
            // says to expect it.
            self::assertStringContainsString(self::SECRET, (string) $e->getPrevious()?->getMessage(), 'the boundary is documented, not silently crossed');
        }
    }

    public function testARuleNamingTheActorFieldIsRefusedRatherThanIgnored(): void
    {
        // "source" is a base field: no redaction rule can reach it, because the actor
        // is chosen by an ActorResolver rather than masked afterwards. Accepting the
        // rule and doing nothing is how somebody believes their actor is redacted.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The actor is chosen by an ActorResolverInterface');

        new ChangeRedactor(['source']);
    }

    public function testEveryBaseFieldIsRefusedTheSameWay(): void
    {
        // Not just the actor: a rule naming any base field could never do anything,
        // and "objectId" in particular looks like a reasonable privacy rule for an
        // application whose ids are email addresses.
        foreach (['id', 'objectType', 'objectId', 'event', 'loggedAt', 'changes'] as $field) {
            try {
                new ChangeRedactor([$field]);
                self::fail(sprintf('"%s" should have been refused', $field));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('base field of every audit record', $e->getMessage());
            }
        }

        // And scoped the same way, because that is how somebody would write it.
        $this->expectException(\InvalidArgumentException::class);

        new ChangeRedactor(['user.objectId']);
    }

    public static function secret(): string
    {
        return self::SECRET;
    }

    /**
     * @param list<string>       $redact
     * @param list<string>       $logs
     */
    /**
     * @param list<string>                     $redact
     * @param list<AuditEnricherInterface>     $enrichers
     */
    private function writer(InMemoryGateway $gateway, array $redact, ?EventDispatcherInterface $events = null, array &$logs = [], FailurePolicy $policy = FailurePolicy::Throw, array $enrichers = []): AuditWriter
    {
        $transport = new SyncTransport($gateway);
        $logger = new class($logs) extends \Psr\Log\AbstractLogger {
            /** @param list<string> $logs */
            public function __construct(private array &$logs)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->logs[] = (string) $message.' '.json_encode(array_map(static fn (mixed $v): mixed => \is_object($v) ? (string) $v : $v, $context), JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        };

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), $enrichers, $policy, $logger, $events, null, new ChangeRedactor($redact, '***'));
    }
}
