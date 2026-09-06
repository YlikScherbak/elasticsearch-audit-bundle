<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\PartialResultException;
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

    public function testASecretNestedInsideAFreeFormChangeIsFoundToo(): void
    {
        // A rule reads as global — "password", anywhere — and it used to see only the
        // field a change is filed under. Here that field is "profile", the secret is a
        // key inside the structure the application put there, and the whole thing went
        // to the index untouched. The name is what a rule names, and a name one level
        // down is still that name.
        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['password']);

        $writer->record('user', 7, 'update', [
            'profile' => new Change(
                ['name' => 'John', 'password' => self::SECRET],
                ['name' => 'John Doe', 'password' => self::SECRET.'-new'],
            ),
        ]);

        $document = $gateway->documents['audit_log'][0];

        self::assertStringNotContainsString(self::SECRET, json_encode($document, \JSON_THROW_ON_ERROR));
        self::assertSame('John', $document['changes']['profile']['old']['name'], 'and what was not named is untouched');
        self::assertSame('***', $document['changes']['profile']['old']['password']);
    }

    public function testASecretNestedInsideAnAttributeIsFoundToo(): void
    {
        // The same reach into the indexed half. The attribute itself is not named by a
        // rule — dropping it whole would take "tenant" with it — so what is masked is
        // the key inside it.
        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['password']);

        $writer->record('user', 7, 'update', ['name' => new Change('a', 'b')], [
            'metadata' => ['tenant' => 'acme', 'credentials' => ['password' => self::SECRET]],
        ]);

        $document = $gateway->documents['audit_log'][0];

        self::assertStringNotContainsString(self::SECRET, json_encode($document, \JSON_THROW_ON_ERROR));
        self::assertSame('acme', $document['metadata']['tenant']);
        self::assertSame('***', $document['metadata']['credentials']['password']);
    }

    public function testASecretInsideAnObjectIsFoundToo(): void
    {
        // `changes` takes mixed by contract, so an object is a legal thing to record —
        // and redaction only walked arrays. The object went past untouched into
        // json_encode, which then wrote every property of it. Redaction has to see the
        // same shape the document will hold.
        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['password']);

        $writer->record('user', 7, 'update', [
            'profile' => new Change(null, (object) ['name' => 'John', 'password' => self::SECRET]),
        ]);

        $document = $gateway->documents['audit_log'][0];

        self::assertStringNotContainsString(self::SECRET, json_encode($document, \JSON_THROW_ON_ERROR));
        self::assertSame('John', $document['changes']['profile']['new']->name ?? $document['changes']['profile']['new']['name']);
    }

    public function testASecretInsideWhatAnObjectSerialisesToIsFoundToo(): void
    {
        // And the object that decides its own shape: what json_encode would write is
        // what redaction reads, so a DTO cannot hide a value behind jsonSerialize().
        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['password']);

        $credentials = new class implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['user' => 'john', 'password' => NothingLeavesUnredactedTest::secret()];
            }
        };

        $writer->record('user', 7, 'update', ['profile' => new Change(null, $credentials)]);

        self::assertStringNotContainsString(self::SECRET, json_encode($gateway->documents, \JSON_THROW_ON_ERROR));
    }

    public function testAValueWithTooMuchToLookThroughIsRefusedRatherThanWritten(): void
    {
        // Depth was bounded and breadth was not, and they are the same question asked
        // twice: a flat list of a hundred thousand entries is one level deep, and
        // walking it happens on the request, before anything is written. The budget is
        // per record rather than per value, because what matters is what one write can
        // ask for.
        $gateway = new InMemoryGateway();
        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Log, [], maxNodes: 50);

        $writer->record('user', 7, 'update', ['metadata' => new Change(null, range(1, 500))]);

        self::assertSame([], $gateway->documents, 'nothing half-checked reaches the index');
        self::assertStringContainsString('more than 50 places to look', implode("\n", $logs));
    }

    public function testTheLimitsAreTheApplicationsToChoose(): void
    {
        // A domain whose records really are that shape says so, rather than losing them.
        $gateway = new InMemoryGateway();
        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Throw, [], maxDepth: 32, maxNodes: 100_000);

        $deep = ['password' => self::SECRET];

        for ($i = 0; $i < 20; ++$i) {
            $deep = ['level'.$i => $deep];
        }

        $writer->record('user', 7, 'update', ['metadata' => new Change(null, $deep)]);

        self::assertCount(1, $gateway->documents['audit_log']);
        self::assertStringNotContainsString(self::SECRET, json_encode($gateway->documents, \JSON_THROW_ON_ERROR), 'and the deeper rule still applies all the way down');
    }

    public function testAValueTooDeepToCheckIsRefusedRatherThanWritten(): void
    {
        // The bound has to exist — this walks data the bundle did not make — and past it
        // the answer was "leave the rest alone", which is a rule that reads as "this
        // name, anywhere" quietly stopping at a depth nobody thinks about. The record is
        // refused instead, and the failure policy says so.
        $gateway = new InMemoryGateway();
        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Log);

        $deep = ['password' => self::SECRET];

        for ($i = 0; $i < 20; ++$i) {
            $deep = ['level'.$i => $deep];
        }

        $writer->record('user', 7, 'update', ['metadata' => new Change(null, $deep)]);

        self::assertSame([], $gateway->documents, 'nothing half-checked reaches the index');
        self::assertStringNotContainsString(self::SECRET, implode("\n", $logs));
        self::assertStringContainsString('nested more than', implode("\n", $logs), 'and the reason names the limit');
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

    public function testOneListenerCannotHandTheSecretToTheNext(): void
    {
        // The record is redacted, dispatched, and redacted again — but the second pass
        // runs after the whole dispatch, so between the two a listener could put a value
        // back and every listener behind it would read it. The document stayed clean,
        // which is why the existing test did not see this: it looks at what reached the
        // gateway. The invariant is wider than that — "not anywhere an application can
        // see it" — and another listener is the application.
        $seen = [];
        $events = new class($seen) implements EventDispatcherInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                if (!$event instanceof RecordCreatedEvent) {
                    return $event;
                }

                // The listener that means well and reaches for the entity again.
                $event->setRecord($event->getRecord()->withChanges(['password' => new Change(null, NothingLeavesUnredactedTest::secret())]));

                // And the one registered after it, which simply reads what it was given.
                $this->seen[] = json_encode($event->getRecord()->changes, JSON_THROW_ON_ERROR);

                return $event;
            }
        };

        $gateway = new InMemoryGateway();
        $writer = $this->writer($gateway, ['password'], $events);
        $writer->record('user', 7, 'update', ['name' => new Change('a', 'b')]);

        self::assertStringNotContainsString(self::SECRET, implode("\n", $seen), 'the next listener reads a redacted record, not the one the first handed back');
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

    public function testTheBundlesOwnWordsAreStillSaidInFull(): void
    {
        // The other half: a sentence this bundle wrote is built from names, counts and
        // statuses and never from a value, and losing it would make a common problem
        // unreadable. Raised through the factory, which is inside the package — that is
        // what makes it the bundle's own.
        $gateway = new InMemoryGateway();
        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Throw);

        try {
            $writer->reportFailure(PartialResultException::shardsFailed(2, 5), new AuditRecord('user', 7, 'update'));
            self::fail('expected a failure');
        } catch (WriteFailedException $e) {
            self::assertStringContainsString('2 of 5 shard(s) failed', $e->getMessage());
        }
    }

    public function testOneOfOurOwnExceptionClassesBuiltElsewhereIsNotOurOwnSentence(): void
    {
        // The gap left by the allowlist. Two of the trusted classes carry free-form
        // prose, and an enricher reusing one to report a problem of its own — which is a
        // helpful thing to try — had its message repeated in the log, the failure event
        // and the exception, past the policy that exists to keep foreign messages out.
        //
        // An exception records the file it was created in, and nothing outside the
        // package can make PHP name a file inside it. So "did we write this sentence"
        // has an answer that does not rely on anybody's good behaviour.
        $gateway = new InMemoryGateway();
        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Log);

        $writer->reportFailure(
            new \Borsche\ElasticsearchAuditBundle\Exception\DeclarationMistake('cannot enrich with token '.self::SECRET),
            new AuditRecord('user', 7, 'update'),
        );

        $said = implode("\n", $logs);

        self::assertStringNotContainsString(self::SECRET, $said);
        self::assertStringContainsString('DeclarationMistake', $said, 'the class still names what failed');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rulesThatCouldNeverMatch')]
    public function testARuleThatCanNeverMatchAnythingIsRefused(string $rule): void
    {
        // The constructor already refuses a base field because "accepting one and
        // quietly ignoring it is how somebody believes an identifier is being redacted".
        // The same thing happens for a rule with no field in it at all — it is accepted
        // and then never matches — and it is likelier: an empty string out of a config
        // list, or "user." from a scope somebody meant to finish.
        $this->expectException(\InvalidArgumentException::class);

        new ChangeRedactor([$rule]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rulesThatCouldNeverMatch(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'a scope with no field' => ['user.'];
        yield 'a field with no scope' => ['.password'];
        // Accepted, because the check trimmed before looking — and then never matched,
        // because the matcher compares the rule as written. A password recorded in full
        // because a config line had a stray space is the failure this whole class is
        // about, arrived at from the least interesting direction.
        yield 'padded' => [' password '];
        yield 'padded scope' => [' user.password'];
        yield 'padded field' => ['user. password'];
    }

    public function testAListenerThatThrowsDoesNotGetToWriteItsMessageIntoTheLog(): void
    {
        // A listener is application code with the same trust level as an enricher, which
        // this bundle explicitly stopped trusting: it is handed a record and may have
        // read anything to build its reply. Its exception was logged with its message
        // and the object itself, past the policy that governs every other cause.
        $gateway = new InMemoryGateway();
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof RecordFailedEvent) {
                    throw new \RuntimeException('while reporting: '.NothingLeavesUnredactedTest::secret());
                }

                return $event;
            }
        };

        $logs = [];
        $writer = $this->writer($gateway, ['password'], $events, $logs, FailurePolicy::Log);
        $writer->reportFailure(new \RuntimeException('the cluster said no'), new AuditRecord('user', 7, 'update'));

        self::assertStringNotContainsString(self::SECRET, implode("\n", $logs));
        self::assertNotEmpty(array_filter($logs, static fn (string $l) => str_contains($l, 'listener')), 'and the failure is still reported');
    }

    public function testApplicationCodeCannotDeclareItsOwnMessageSafe(): void
    {
        // SafeExceptionMessage is a promise the bundle makes about sentences it wrote
        // itself. As a public empty interface it was also an offer: any class could
        // implement it and have its message repeated in the log, the failure event and
        // the exception — past the very policy that exists because an enricher's message
        // may quote what was just redacted. The same enricher the suite already treats
        // as untrusted, one `implements` away from being trusted.
        $enricher = new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                throw new SelfDeclaredSafeException('cannot enrich with token '.NothingLeavesUnredactedTest::secret());
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $gateway = new InMemoryGateway();
        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Log, [$enricher]);
        $writer->record('user', 7, 'update', ['name' => new Change('a', 'b')]);

        self::assertStringNotContainsString(self::SECRET, implode("\n", $logs));
    }

    public function testAClassNameIsNotWhatMakesAMessageTrusted(): void
    {
        // The rule was "declares the marker, and its class name starts with the bundle's
        // exception namespace". PHP lets any file declare a class in any namespace, so
        // the second half was a file header away from being satisfied — by accident as
        // easily as on purpose, since a project that copies an exception out of a
        // dependency keeps the namespace with it. The trusted set is named class by
        // class now, and this one is not in it.
        require_once __DIR__.'/../Fixtures/NamespaceSquatter.php';

        $enricher = new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                throw new \Borsche\ElasticsearchAuditBundle\Exception\SquattedSafeException('cannot enrich with token '.NothingLeavesUnredactedTest::secret());
            }

            public function mapping(): array
            {
                return [];
            }
        };

        $gateway = new InMemoryGateway();
        $logs = [];
        $writer = $this->writer($gateway, ['password'], null, $logs, FailurePolicy::Log, [$enricher]);
        $writer->record('user', 7, 'update', ['name' => new Change('a', 'b')]);

        self::assertStringNotContainsString(self::SECRET, implode("\n", $logs));
    }

    public function testASafeExceptionDoesNotCarryAnUnsafeOneAlongWithIt(): void
    {
        // SafeExceptionMessage vouches for a message, and the failure path was reading
        // it as vouching for the whole object. IndexNotFoundException::forIndex() takes
        // a previous, and the gateway hands it the cluster's exception; a log processor
        // walking getPrevious() — which is why FailureReason exists — then finds exactly
        // the text the safe message was chosen to avoid.
        $gateway = new InMemoryGateway();
        $seen = [];
        $events = new class($seen) implements EventDispatcherInterface {
            /** @param list<object> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof RecordFailedEvent) {
                    $this->seen[] = $event;
                }

                return $event;
            }
        };

        $logs = [];
        $writer = $this->writer($gateway, ['password'], $events, $logs, FailurePolicy::Log);

        // How the gateway raises it: the cluster's own exception travels as previous,
        // and the sentence in front of it is the bundle's own.
        $writer->reportFailure(
            IndexNotFoundException::forIndex('audit_log', new \RuntimeException('rejected value '.self::SECRET)),
            new AuditRecord('user', 7, 'update'),
        );

        $reason = $seen[0]->reason;

        self::assertStringContainsString('audit_log', $reason->getMessage(), 'the safe sentence is still said in full — it is the diagnostic');
        self::assertNull($reason->getPrevious(), 'and nothing walkable hangs off it');
        self::assertStringNotContainsString(self::SECRET, self::everythingObservable($reason, $logs, $gateway));
    }

    /**
     * @param list<string> $logs
     */
    private static function everythingObservable(\Throwable $reason, array $logs, InMemoryGateway $gateway): string
    {
        $chain = [];

        for ($e = $reason; $e !== null; $e = $e->getPrevious()) {
            $chain[] = $e::class.': '.$e->getMessage();
        }

        return implode("\n", [...$chain, ...$logs, json_encode($gateway->documents, JSON_THROW_ON_ERROR)]);
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

            // And the chain with it. getPrevious() is not a private debugging channel:
            // an uncaught WriteFailedException reaches Symfony's error handler, Monolog's
            // exception processor, Sentry — every one of which serialises the whole
            // chain. The policy would then be walked around by another logger. So
            // "cause" means cause everywhere, the thrown exception included; "full" is
            // where a caller asks for the raw diagnostic.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                self::assertStringNotContainsString(self::SECRET, $cause->getMessage(), 'nothing in the chain repeats it either');
            }
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
    private function writer(InMemoryGateway $gateway, array $redact, ?EventDispatcherInterface $events = null, array &$logs = [], FailurePolicy $policy = FailurePolicy::Throw, array $enrichers = [], int $maxDepth = ChangeRedactor::DEFAULT_MAX_DEPTH, int $maxNodes = ChangeRedactor::DEFAULT_MAX_NODES): AuditWriter
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

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), $enrichers, $policy, $logger, $events, null, new ChangeRedactor($redact, '***', $maxDepth, $maxNodes));
    }
}

/**
 * What an application can write, and what the bundle must not take at face value.
 */
final class SelfDeclaredSafeException extends \RuntimeException implements \Borsche\ElasticsearchAuditBundle\Exception\SafeExceptionMessage
{
}

