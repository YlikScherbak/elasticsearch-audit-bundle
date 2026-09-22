<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MomentEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Exception\AuditException;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailureDetails;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\OutboxTransport;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\MessengerTransport;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;

/**
 * One secret, every channel, every way an operation can end.
 *
 * The tests next door check the record: that a redacted value is not in the document
 * that reaches Elasticsearch. This one checks everything *else* — because the last
 * value this bundle let escape did not escape through a record. It was a DSN with a
 * password in it, inside the message of an exception, and what found it was a person
 * reading the code rather than any of the two hundred tests that were already green.
 *
 * So the shape here is deliberate: every observable channel is collected into one
 * place, and each scenario ends by looking for the marker in all of them at once —
 * the log at every level, **message and context**, the whole `getPrevious()` chain of
 * whatever was raised, the events, the bytes actually put on the wire to the cluster,
 * and the row written into the outbox queue. A channel added later is swept by every
 * scenario the moment it is collected here, without anyone remembering this file
 * exists.
 *
 * The marker is never a value the bundle is allowed to write: it lives in a field a
 * redaction rule covers, and in the messages of code the bundle did not write. Under
 * the default `failure_details: cause` neither may travel.
 */
final class EveryChannelSweepTest extends TestCase
{
    private const MARKER = 'LEAK_MARKER_7c1f2a';

    /** @var array<string, mixed> everything an application could observe */
    private array $channels = [];

    protected function setUp(): void
    {
        $this->channels = [];
    }

    public function testASuccessfulWriteCarriesOnlyThePlaceholder(): void
    {
        // The baseline. If the marker is absent here the sweep is measuring something,
        // and the assertion below about the placeholder is what says so.
        $writer = $this->writer(FailurePolicy::Log);

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::MARKER)], ['password' => self::MARKER]);

        $this->assertNothingLeaked();
        // The placeholder did travel, which is what says the sweep looked at a real
        // document rather than at nothing.
        self::assertStringContainsString('***', (string) json_encode($this->channels['wire'] ?? []), 'nothing was written at all, so nothing was swept');
    }

    public function testAClusterQuotingTheDocumentBackLeaksNothing(): void
    {
        // The realistic leak, and the reason include_source_on_error exists: a cluster
        // that refuses a document repeats it in the error, and that error travels the
        // whole way back through the bundle's own exception, its log line and its
        // failure event.
        $writer = $this->writer(FailurePolicy::Log, refuseWith: [
            'error' => [
                'type' => 'document_parsing_exception',
                'reason' => sprintf('failed to parse field [password] of type [keyword]: the document was {"password":"%s"}', self::MARKER),
            ],
        ]);

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::MARKER)]);

        $this->assertNothingLeaked();
    }

    public function testTheSameRefusalRaisedRatherThanLoggedLeaksNothingEither(): void
    {
        // Under on_failure: throw the caller gets an exception, and an exception carries
        // a chain. Every logger an application has serialises that chain, so the sweep
        // has to walk it too — this is the channel the DSN went out through.
        $writer = $this->writer(FailurePolicy::Throw, refuseWith: [
            'error' => ['type' => 'document_parsing_exception', 'reason' => 'the document was '.self::MARKER],
        ]);

        try {
            $writer->record('user', 7, 'update', ['password' => new Change(null, self::MARKER)]);
            self::fail('the write should have raised');
        } catch (AuditException $e) {
            $this->collectRaised($e);
        }

        $this->assertNothingLeaked();
    }

    public function testAnEnrichersOwnExceptionLeaksNothing(): void
    {
        // Code the bundle did not write, failing with the value in its message — the
        // shape of "authorization failed with token …". Nothing the bundle emits may
        // repeat it, whatever the record was about.
        $writer = $this->writer(FailurePolicy::Log, enrichers: [new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                throw new \RuntimeException('an enricher failed while holding '.EveryChannelSweepTest::marker());
            }

            public function mapping(): array
            {
                return [];
            }
        }]);

        $writer->record('user', 7, 'update', ['status' => new Change('a', 'b')]);

        $this->assertNothingLeaked();
    }

    public function testAComparatorsOwnExceptionLeaksNothing(): void
    {
        $buffer = new FrameBuffer(new ValueComparator([new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('a comparator failed while holding '.EveryChannelSweepTest::marker());
            }
        }]));

        $writer = $this->writer(FailurePolicy::Log, buffer: $buffer);

        $buffer->open();
        $writer->record('user', 7, 'update', ['password' => new Change(null, self::MARKER)]);
        $writer->writeManyCompleted($buffer->close() ?? []);

        $this->assertNothingLeaked();
    }

    public function testARecordRefusedByTheRedactionLimitLeaksNothing(): void
    {
        // The record never goes out, and the refusal says why — by naming the limit,
        // never the value that was being walked when it ran out.
        $writer = $this->writer(FailurePolicy::Log, maxNodes: 2);

        $writer->record('user', 7, 'update', ['payload' => [
            'a' => ['b' => ['c' => ['password' => self::MARKER]]],
            'd' => ['e' => ['f' => ['password' => self::MARKER]]],
        ]]);

        $this->assertNothingLeaked();
    }

    public function testARecordAListenerVetoedLeaksNothing(): void
    {
        $writer = $this->writer(FailurePolicy::Log, veto: true);

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::MARKER)]);

        $this->assertNothingLeaked();
    }

    public function testTwoEnrichersDisagreeingAboutARedactedFieldLeakNothing(): void
    {
        // A moment enricher and an ordinary one setting the same attribute: the writer
        // keeps the moment's value and says so, because an application with two
        // enrichers and no message has no way to find out which of them does nothing.
        // Saying so put both values in a log line, and this one runs before redaction —
        // so the field a rule covers was in the clear in the one place nobody looks,
        // while the document had only the placeholder.
        $writer = $this->writer(FailurePolicy::Log, enrichers: [
            new class implements MomentEnricherInterface {
                public function describe(): array
                {
                    // Nested under a key no rule names: the rules name a field, redaction
                    // walks into the values, and asking the redactor about the top key
                    // was the version of this guard that let the one below through.
                    return ['context' => ['password' => EveryChannelSweepTest::secret()]];
                }

                public function mapping(): array
                {
                    return [];
                }
            },
            new class implements AuditEnricherInterface {
                public function supports(AuditRecord $record): bool
                {
                    return true;
                }

                public function enrich(AuditRecord $record): AuditRecord
                {
                    return $record->withAttributes(['context' => ['password' => 'the other '.EveryChannelSweepTest::secret()]]);
                }

                public function mapping(): array
                {
                    return [];
                }
            },
        ]);

        $writer->record('user', 7, 'update', ['name' => new Change('a', 'b')]);

        $this->assertNothingLeaked();
    }

    public function testAMomentEnricherThatThrowsQuotingTheSecretLeaksNothing(): void
    {
        // The other new channel: an enricher asked to read the request throws, and its
        // own message is as likely to quote a token as a cluster's error is to quote a
        // document. Under the default policy the bundle does not repeat it.
        $writer = $this->writer(FailurePolicy::Log, enrichers: [
            new class implements MomentEnricherInterface {
                public function describe(): array
                {
                    throw new \RuntimeException('Authorization: Bearer '.EveryChannelSweepTest::secret());
                }

                public function mapping(): array
                {
                    return [];
                }
            },
        ]);

        $writer->record('user', 7, 'update', ['name' => new Change('a', 'b')]);

        $this->assertNothingLeaked();
    }

    public function testTheRowTheOutboxCommitsCarriesOnlyThePlaceholder(): void
    {
        // The last channel, and the one that keeps what it is given for as long as the
        // queue does: a record written through the outbox is a row in the application's
        // own database, read back by a worker minutes or hours later. A value that got
        // that far is not in a log somebody rotates — it is in a table with a backup.
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the outbox row.');
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = new QueueConnection(['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false], $connection);
        $queue->setup();

        $context = new OutboxContext();
        $sender = new class($queue) implements SenderInterface {
            public function __construct(private readonly QueueConnection $queue)
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                $encoded = (new PhpSerializer())->encode($envelope);
                $this->queue->send($encoded['body'], $encoded['headers'] ?? []);

                return $envelope;
            }
        };

        $transport = new OutboxTransport($sender, $context, onlyInsideATransaction: false);

        $writer = new AuditWriter(
            $transport,
            new SyncTransport($this->gateway(null)),
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'tests'),
            new FrozenClock(),
            [],
            FailurePolicy::Log,
            $this->logger(),
            $this->events(false),
            null,
            new ChangeRedactor(['password']),
        );

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::MARKER)], ['password' => self::MARKER]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->fetchAllAssociative('SELECT * FROM audit_outbox');
        $this->channels['outbox'] = $rows;

        self::assertCount(1, $rows, 'nothing reached the queue, so nothing was swept');
        $this->assertNothingLeaked();
    }

    public function testWhatTheWorkerLeavesInTheFailureTransportCarriesOnlyThePlaceholder(): void
    {
        // The asynchronous road, and the one channel on it that nobody else observes.
        // A record dispatched to a queue leaves the request having succeeded; the
        // handler runs in a worker minutes later, the cluster refuses the document, and
        // once the retries run out Symfony keeps the failure as an ErrorDetailsStamp —
        // built from FlattenException, which walks getPrevious() and keeps every message
        // it finds — and serialises the whole envelope into the failure transport.
        //
        // Nothing in the request that made the change ever sees any of that. If the
        // cluster quoted the refused document back, it does not arrive in a log somebody
        // rotates: it is a row in a table with a backup, and no line anywhere says so.
        // The queued message is swept with it, because a value that must not be logged
        // must not be waiting in a queue either.
        $refusal = ['error' => ['type' => 'document_parsing_exception', 'reason' => "failed to parse field [password]. Preview of field's value: '".self::MARKER."'"]];

        $queued = &$this->channels['queued message'];
        $queued = [];
        $stored = &$this->channels['failure transport'];
        $stored = [];

        $handler = new IndexAuditRecordHandler($this->gateway($refusal));

        $bus = new class($handler, $queued, $stored) implements MessageBusInterface {
            /**
             * @param list<array<string, mixed>> $queued
             * @param list<array<string, mixed>> $stored
             */
            public function __construct(
                private readonly IndexAuditRecordHandler $handler,
                private array &$queued,
                private array &$stored,
            ) {
            }

            /**
             * @param array<object> $stamps
             */
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                $serializer = new PhpSerializer();

                // The row the queue holds while the message waits its turn.
                $this->queued[] = $serializer->encode($envelope);

                try {
                    // What the worker does on the other side of it.
                    ($this->handler)($message);
                } catch (\Throwable $thrown) {
                    // And what Symfony stores once the attempts are spent.
                    $this->stored[] = $serializer->encode($envelope->with(ErrorDetailsStamp::create($thrown)));
                }

                // An async dispatch comes back successful whatever happens later: the
                // caller is long gone by then, which is the whole reason this channel
                // needs sweeping rather than watching.
                return $envelope;
            }
        };

        $transport = new MessengerTransport($bus);

        $writer = new AuditWriter(
            $transport,
            $transport,
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'tests'),
            new FrozenClock(),
            [],
            FailurePolicy::Log,
            $this->logger(),
            $this->events(false),
            null,
            new ChangeRedactor(['password']),
        );

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::MARKER)], ['password' => self::MARKER]);

        self::assertNotSame([], $stored, 'the handler did not fail, so the channel this test is about was never written');
        $this->assertNothingLeaked();
    }

    public function testTheSweepIsSensitiveAndFullDetailsIsExactlyWhatItSounds(): void
    {
        // Both halves of one fact. A sweep that cannot see a leak proves nothing, so
        // here is the configuration in which the value really does travel — and it is
        // the configuration whose whole meaning is "repeat what other code said". An
        // application that turns it on has decided that; what it must not do is arrive
        // at it by accident, which is why the default is the other one.
        $writer = $this->writer(FailurePolicy::Log, refuseWith: [
            'error' => ['type' => 'document_parsing_exception', 'reason' => 'the document was '.self::MARKER],
        ], failureDetails: FailureDetails::Full);

        $writer->record('user', 7, 'update', ['password' => new Change(null, self::MARKER)]);

        $everything = (string) json_encode($this->channels, \JSON_PARTIAL_OUTPUT_ON_ERROR);

        self::assertStringContainsString(self::MARKER, $everything, 'the sweep cannot see a leak even when one is asked for, so its silence means nothing');
    }

    public static function marker(): string
    {
        return self::MARKER;
    }

    /**
     * Everything observable, in one string. Serialised loosely on purpose — what is
     * being asked is "does this text appear anywhere", and a channel that cannot be
     * encoded cleanly still has to be looked at.
     */
    /**
     * The marker, readable from the anonymous enrichers the scenarios build.
     */
    public static function secret(): string
    {
        return self::MARKER;
    }

    private function assertNothingLeaked(): void
    {
        foreach ($this->channels as $name => $channel) {
            $text = (string) json_encode($channel, \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);

            self::assertStringNotContainsString(self::MARKER, $text, sprintf('the marker reached the "%s" channel', $name));
        }

        self::assertNotSame([], $this->channels, 'nothing was collected, so nothing was swept');
    }

    private function collectRaised(\Throwable $e): void
    {
        $chain = [];

        for ($link = $e; $link !== null; $link = $link->getPrevious()) {
            $chain[] = ['class' => $link::class, 'message' => $link->getMessage()];
        }

        $this->channels['raised'] = $chain;
    }

    /**
     * @param array<string, mixed>|null      $refuseWith what the cluster answers a write with
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    private function writer(
        FailurePolicy $policy,
        ?array $refuseWith = null,
        iterable $enrichers = [],
        ?FrameBuffer $buffer = null,
        int $maxNodes = 10_000,
        bool $veto = false,
        FailureDetails $failureDetails = FailureDetails::Cause,
    ): AuditWriter {
        $transport = new SyncTransport($this->gateway($refuseWith));

        return new AuditWriter(
            $transport,
            $transport,
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'tests'),
            new FrozenClock(),
            $enrichers,
            $policy,
            $this->logger(),
            $this->events($veto),
            $buffer,
            new ChangeRedactor(['password'], maxNodes: $maxNodes),
            failureDetails: $failureDetails,
        );
    }

    /**
     * A real gateway over a scripted HTTP client, so the sweep sees the bytes rather
     * than an in-memory stand-in: a leak into a request the client builds — a host
     * with credentials in it, a body nobody looked at — is invisible to a fake.
     *
     * @param array<string, mixed>|null $refuseWith
     */
    private function gateway(?array $refuseWith): ElasticsearchGateway
    {
        $wire = &$this->channels['wire'];
        $wire = [];

        $http = new class($wire, $refuseWith) implements ClientInterface {
            /** @param list<array<string, string>> $wire */
            public function __construct(private array &$wire, private readonly ?array $refuseWith)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $path = $request->getUri()->getPath();

                if ($request->getMethod() === 'HEAD') {
                    return self::answer(200, []);
                }

                $this->wire[] = [
                    'uri' => (string) $request->getUri(),
                    'headers' => (string) json_encode($request->getHeaders()),
                    'body' => (string) $request->getBody(),
                ];

                if ($path === '/') {
                    return self::answer(200, ['version' => ['number' => '8.19.0']]);
                }

                return $this->refuseWith === null
                    ? self::answer(200, ['_id' => 'a', 'result' => 'created'])
                    : self::answer(400, $this->refuseWith);
            }

            /**
             * @param array<string, mixed> $body
             */
            private static function answer(int $status, array $body): ResponseInterface
            {
                return new Response($status, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], (string) json_encode($body));
            }
        };

        return new ElasticsearchGateway(ClientBuilder::create()->setHosts(['http://es.test:9200'])->setHttpClient($http)->build(), false);
    }

    private function logger(): AbstractLogger
    {
        $logs = &$this->channels['logs'];
        $logs = [];

        return new class($logs) extends AbstractLogger {
            /** @param list<array<string, mixed>> $logs */
            public function __construct(private array &$logs)
            {
            }

            /**
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                // Level, message and context together, with any exception in the context
                // unwound into its whole chain: that is what a Monolog handler writes,
                // and writing less here would sweep less than production does.
                $this->logs[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => array_map(self::readable(...), $context),
                ];
            }

            private static function readable(mixed $value): mixed
            {
                if (!$value instanceof \Throwable) {
                    return $value;
                }

                $chain = [];

                for ($link = $value; $link !== null; $link = $link->getPrevious()) {
                    $chain[] = $link::class.': '.$link->getMessage();
                }

                return $chain;
            }
        };
    }

    private function events(bool $veto): EventDispatcherInterface
    {
        $seen = &$this->channels['events'];
        $seen = [];

        return new class($seen, $veto) implements EventDispatcherInterface {
            /** @param list<array<string, mixed>> $seen */
            public function __construct(private array &$seen, private readonly bool $veto)
            {
            }

            public function dispatch(object $event): object
            {
                $this->seen[] = ['class' => $event::class] + self::readable($event);

                if ($this->veto && method_exists($event, 'cancel')) {
                    $event->cancel();
                }

                return $event;
            }

            /**
             * @return array<string, mixed>
             */
            private static function readable(object $event): array
            {
                $out = [];

                foreach ((new \ReflectionObject($event))->getProperties() as $property) {
                    $value = $property->getValue($event);
                    $out[$property->getName()] = $value instanceof \Throwable
                        ? $value::class.': '.$value->getMessage()
                        : $value;
                }

                return $out;
            }
        };
    }
}
