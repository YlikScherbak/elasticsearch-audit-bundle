<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\MessengerTransport;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Elastic\Transport\Serializer\JsonSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * What a transport is handed: scalars, arrays and stdClass objects built by the bundle,
 * and nothing the application made.
 *
 * Redaction used to read a value and hand the value on. For an array that is the same
 * thing; for an object it is not. The redactor read what jsonSerialize() answered, or
 * the public properties, and gave the object itself to whatever came next - which asked
 * it again. A client encoding the body called jsonSerialize() a second time and wrote
 * whatever it said then; Messenger's PhpSerializer did not ask at all and put every
 * property in the queue, the private ones included. The index looked clean while the
 * queue row - a table of the application's own database, with a backup - held what the
 * application never meant to serialise. And without a single rule configured nothing
 * read the record at all, so the same went into the queue with no redactor in sight.
 *
 * Now the value that was read is the value that travels. Where it stands decides what a
 * date or an enum becomes: inside `changes`, which is stored unindexed, the form a
 * Change's sides have always had; in an attribute, indexed by somebody's mapping,
 * exactly what json_encode made of it before.
 */
final class WhatTheTransportIsHandedTest extends TestCase
{
    private const SECRET = 'HANDED_SECRET_5e1b';

    /** @var list<string> */
    private array $logs = [];

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->logs = [];
        $this->events = [];
        CountsItsAnswers::$asked = 0;
    }

    /** @return iterable<string, array{?ChangeRedactor}> */
    public static function redactors(): iterable
    {
        yield 'with a rule' => [new ChangeRedactor(['password'])];
        yield 'with no rule at all' => [null];
    }

    #[DataProvider('redactors')]
    public function testAPrivatePropertyNeverReachesTheQueue(?ChangeRedactor $redactor): void
    {
        $bus = $this->bus();
        $this->writer(new MessengerTransport($bus), $redactor)->record('user', 1, AuditEvent::UPDATE, [
            'profile' => new HoldsAPrivateSecret(),
            'settings' => new Change(new HoldsAPrivateSecret(), new SerialisesAndHoldsASecret()),
        ], ['owner' => new HoldsAPrivateSecret()]);

        self::assertCount(1, $bus->sent);
        $body = stripslashes((new PhpSerializer())->encode($bus->sent[0])['body']);

        self::assertStringNotContainsString(self::SECRET, $body, 'the queued row held a property nobody serialised');
        self::assertStringNotContainsString(HoldsAPrivateSecret::class, $body, 'the queued row names a class of the application, which a deploy can rename');
        $this->assertOnlyPlainValues($bus->sent[0]->getMessage()->document);
    }

    #[DataProvider('redactors')]
    public function testWhatWasReadIsWhatIsWritten(?ChangeRedactor $redactor): void
    {
        $gateway = new InMemoryGateway();
        $this->writer(new SyncTransport($gateway), $redactor)->record('user', 1, AuditEvent::UPDATE, ['profile' => new AnswersDifferentlyEachTime()]);

        $body = (new JsonSerializer())->serialize($gateway->only('audit_log'));

        self::assertStringNotContainsString(self::SECRET, $body);
        self::assertStringContainsString('"profile":{"status":"active"}', $body, 'the first answer is the one written');
    }

    public function testARuleStillReachesInsideAnObject(): void
    {
        $gateway = new InMemoryGateway();
        $this->writer(new SyncTransport($gateway), new ChangeRedactor(['password']))->record('user', 1, AuditEvent::UPDATE, [
            'profile' => new HoldsAPublicPassword(),
        ]);

        self::assertEquals((object) ['status' => 'active', 'password' => '***', 'note' => 'set'], $gateway->only('audit_log')['changes']['profile']);
    }

    /**
     * Inside `changes` a date and an enum take the form a Change's sides have always had,
     * wherever they stand and whatever holds them.
     */
    public function testInsideTheChangesADateAndAnEnumTakeTheFormOfAChange(): void
    {
        $at = new \DateTimeImmutable('2026-10-07 10:00:00.250000', new \DateTimeZone('Europe/Kyiv'));
        $gateway = new InMemoryGateway();

        $this->writer(new SyncTransport($gateway))->record('user', 1, AuditEvent::UPDATE, [
            'side' => new Change(null, $at),
            'inArray' => new Change(null, ['at' => $at, 'kind' => Suit::Hearts, 'pure' => Bare::One]),
            'inObject' => new Change(null, new HoldsADate($at)),
            'inSerialised' => new Change(null, new SerialisesADate($at)),
            'raw' => $at,
            'rawObject' => new HoldsADate($at),
            'pair' => ['old' => null, 'new' => $at, 'note' => Bare::One],
        ]);

        // Read from the client's own encoding, which drops nulls: an `old` of null is not
        // on the wire, and was not before.
        $changes = json_decode((new JsonSerializer())->serialize($gateway->only('audit_log')), true)['changes'];
        $form = '2026-10-07 07:00:00.250000';

        self::assertSame(['new' => $form], $changes['side']);
        self::assertSame(['at' => $form, 'kind' => 'H', 'pure' => 'One'], $changes['inArray']['new']);
        self::assertEquals(['at' => $form, 'kind' => 'H'], $changes['inObject']['new']);
        self::assertSame(['at' => $form, 'kind' => 'H'], $changes['inSerialised']['new']);
        self::assertSame($form, $changes['raw']);
        self::assertEquals(['at' => $form, 'kind' => 'H'], $changes['rawObject']);
        self::assertSame(['new' => $form, 'note' => 'One'], $changes['pair']);
    }

    /**
     * An attribute is indexed by somebody's mapping, so a date in one is what json_encode
     * made of it before: the same object, built anew, with nothing private in it.
     */
    public function testInAnAttributeADateIsWhatJsonEncodeMadeOfIt(): void
    {
        $plain = new \DateTimeImmutable('2026-10-07 10:00:00.250000', new \DateTimeZone('Europe/Kyiv'));
        $sub = new ADateWithSecrets('2026-10-07 10:00:00', new \DateTimeZone('UTC'));
        $own = new ADateThatSerialisesItself('2026-10-07 10:00:00', new \DateTimeZone('UTC'));
        $gateway = new InMemoryGateway();

        $this->writer(new SyncTransport($gateway), new ChangeRedactor(['password']))->record('user', 1, AuditEvent::UPDATE, [], [
            'plain' => $plain,
            'sub' => $sub,
            'own' => $own,
            'kind' => Suit::Hearts,
            'pure' => Bare::One,
        ]);

        $document = json_decode((new JsonSerializer())->serialize($gateway->only('audit_log')), true);

        self::assertSame(json_decode((string) json_encode($plain), true), $document['plain'], 'the form an attribute had before');
        self::assertSame(['visible' => 'shown', 'password' => '***'] + json_decode((string) json_encode(new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('UTC'))), true), $document['sub'], 'a public password of a date is still a password');
        self::assertSame(['at' => 'its own'], $document['own'], 'a date that serialises itself is what it says, as json_encode wrote it');
        self::assertSame('H', $document['kind']);
        self::assertSame('One', $document['pure'], 'a pure enum is written by its name rather than losing the record');
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($document));
    }

    public function testAPrivateSecretOfADateStaysBehindInEveryPlace(): void
    {
        $bus = $this->bus();
        $date = new ADateWithSecrets('2026-10-07 10:00:00', new \DateTimeZone('UTC'));

        // With the rule, because the date's public password is a password like any other;
        // its private token is what nothing may carry, rule or not.
        $this->writer(new MessengerTransport($bus), new ChangeRedactor(['password']))->record('user', 1, AuditEvent::UPDATE, [
            'at' => new Change(null, $date),
            'raw' => $date,
        ], ['at' => $date]);

        $body = stripslashes((new PhpSerializer())->encode($bus->sent[0])['body']);

        self::assertStringNotContainsString(self::SECRET, $body);
        self::assertStringNotContainsString(ADateWithSecrets::class, $body);
    }

    public function testAThousandHopsAreFollowedAndTheThousandAndFirstIsRefused(): void
    {
        $gateway = new InMemoryGateway();
        $writer = $this->writer(new SyncTransport($gateway));

        $writer->record('user', 1, AuditEvent::UPDATE, ['chain' => new Hops(1000)]);
        self::assertSame(['end' => true], (array) $gateway->only('audit_log')['changes']['chain']);

        $gateway->documents = [];
        $writer->record('user', 2, AuditEvent::UPDATE, ['chain' => new Hops(1001)]);

        self::assertSame([], $gateway->documents);
        self::assertStringContainsString('jsonSerialize() 1000 times', implode("\n", $this->logs));
    }

    public function testManyObjectsSideBySideAreNotAChain(): void
    {
        $gateway = new InMemoryGateway();
        $this->writer(new SyncTransport($gateway))->record('user', 1, AuditEvent::UPDATE, ['many' => array_map(static fn (): Hops => new Hops(1), range(1, 1001))]);

        self::assertCount(1001, $gateway->only('audit_log')['changes']['many']);
    }

    public function testOneObjectInTwoPlacesIsNotACircle(): void
    {
        $shared = new HoldsAPrivateSecret();
        $gateway = new InMemoryGateway();

        $this->writer(new SyncTransport($gateway))->record('user', 1, AuditEvent::UPDATE, ['a' => $shared, 'b' => [$shared, $shared]]);

        self::assertCount(1, $gateway->documents['audit_log'] ?? []);
    }

    #[DataProvider('redactors')]
    public function testAnObjectThatHoldsItselfIsRefused(?ChangeRedactor $redactor): void
    {
        $loop = new \stdClass();
        $loop->self = $loop;
        $gateway = new InMemoryGateway();

        $this->writer(new SyncTransport($gateway), $redactor)->record('user', 1, AuditEvent::UPDATE, ['loop' => $loop]);

        self::assertSame([], $gateway->documents);
        self::assertStringContainsString('leads back into itself', implode("\n", $this->logs));
        // Said as what it is: with a rule it is redaction that cannot see the bottom; without
        // one there is no redaction to blame, and the value simply cannot be serialised.
        self::assertStringContainsString($redactor === null ? 'so it could not be serialised' : 'redaction cannot see the bottom of it', implode("\n", $this->logs));
    }

    public function testJsonSerializeIsAskedOnceAcrossBothPassesAndTheFailure(): void
    {
        // A listener on RecordCreatedEvent makes the writer redact twice, and a refusing
        // cluster sends the record through the failure path: three readings of one record.
        $gateway = new InMemoryGateway();
        $gateway->failWith = new \RuntimeException('the cluster is down');

        $this->writer(new SyncTransport($gateway), new ChangeRedactor(['password']), listen: true)->record('user', 1, AuditEvent::UPDATE, ['counted' => new CountsItsAnswers()]);

        self::assertSame(1, CountsItsAnswers::$asked);
    }

    public function testAFailureHalfwayThroughLeavesTheObjectUnaskedOnTheFailurePath(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);

        $gateway = new InMemoryGateway();
        $this->writer(new SyncTransport($gateway), new ChangeRedactor(['password']))->record('user', 1, AuditEvent::UPDATE, [
            'counted' => new CountsItsAnswers(),
            'content' => $stream,
        ]);

        self::assertSame([], $gateway->documents, 'a stream is not a value an index can hold');
        self::assertSame(1, CountsItsAnswers::$asked, 'the failure path asked the object again');
        self::assertStringContainsString('resource', implode("\n", $this->logs));

        foreach ($this->events as $event) {
            if ($event instanceof RecordFailedEvent) {
                self::assertStringNotContainsString(CountsItsAnswers::class, print_r($event->getRecord(), true), 'the failure carried the object the record was refused for');
            }
        }
    }

    public function testAListenersNewObjectIsAskedOnceToo(): void
    {
        $gateway = new InMemoryGateway();
        $writer = $this->writer(new SyncTransport($gateway), new ChangeRedactor(['password']), replaceWith: new CountsItsAnswers());

        $writer->record('user', 1, AuditEvent::UPDATE, ['name' => new Change('a', 'b')]);

        self::assertSame(1, CountsItsAnswers::$asked);
        self::assertSame(['status' => 'active'], (array) $gateway->only('audit_log')['changes']['added']);
    }

    public function testAnEmptyObjectIsStillAnObjectAfterTheQueue(): void
    {
        $bus = $this->bus();
        $this->writer(new MessengerTransport($bus))->record('user', 1, AuditEvent::UPDATE, [
            'none' => new HoldsAPrivateSecret(),
            'empty' => new \stdClass(),
            'keyed' => [3 => 'three', 7 => 'seven'],
        ]);

        $serializer = new PhpSerializer();
        $decoded = $serializer->decode($serializer->encode($bus->sent[0]))->getMessage();
        self::assertInstanceOf(IndexAuditRecord::class, $decoded);

        $json = (new JsonSerializer())->serialize($decoded->document['changes']);

        self::assertSame('{"none":{"status":"active"},"empty":{},"keyed":{"3":"three","7":"seven"}}', $json);
    }

    public function testTheApplicationsObjectIsLeftAsItWas(): void
    {
        // The client walks a body by reference and unsets every null it finds - in the
        // application's own object, when that object is what it was handed.
        $object = new HoldsAPublicPassword();
        $object->note = null;
        $before = serialize($object);
        $gateway = new InMemoryGateway();

        $this->writer(new SyncTransport($gateway), new ChangeRedactor(['password']))->record('user', 1, AuditEvent::UPDATE, ['profile' => $object]);
        (new JsonSerializer())->serialize($gateway->only('audit_log'));

        self::assertSame($before, serialize($object));
    }

    /**
     * A readonly property, or an enum anywhere but on a Change's side, made the client
     * throw: it takes every property by reference to drop the nulls, and PHP refuses a
     * reference to a readonly one. The record was lost on encoding.
     */
    public function testAReadonlyObjectAndAnEnumReachTheWire(): void
    {
        $gateway = new InMemoryGateway();

        $this->writer(new SyncTransport($gateway))->record('user', 1, AuditEvent::UPDATE, [
            'profile' => new ReadsOnly(),
            'kind' => Suit::Hearts,
        ], ['kind' => Suit::Hearts]);

        self::assertSame(
            '{"profile":{"status":"active"},"kind":"H"}',
            (string) json_encode(json_decode((new JsonSerializer())->serialize($gateway->only('audit_log')), true)['changes']),
        );
    }

    public function testAStreamIsRefusedBeforeTheQueue(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        $bus = $this->bus();

        $this->writer(new MessengerTransport($bus))->record('user', 1, AuditEvent::UPDATE, ['content' => new Change(null, $stream)]);

        self::assertSame([], $bus->sent);
    }

    /**
     * Without a rule nothing is bounded but what json_encode bounds: 512 nested levels of
     * the whole document. What fits is written; one level more was lost on encoding before
     * and is refused now, earlier and by name.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function deepest(): iterable
    {
        // The document is one level, `changes` a second: a raw value starts at the third,
        // a Change's side at the fourth (its pair is the third), an attribute at the second.
        yield 'a raw value' => [['deep' => self::nest(510)], []];
        yield 'a side of a Change' => [['deep' => new Change(null, self::nest(509))], []];
        yield 'an attribute' => [[], ['deep' => self::nest(511)]];
        yield 'an object serialising to arrays' => [['deep' => new NestsByArrays(510)], []];
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('deepest')]
    public function testTheDeepestValueJsonCanEncodeIsWrittenAndOneMoreIsRefused(array $changes, array $attributes): void
    {
        $gateway = new InMemoryGateway();
        $writer = $this->writer(new SyncTransport($gateway));

        $writer->record('user', 1, AuditEvent::UPDATE, $changes, $attributes);
        $written = $gateway->only('audit_log');
        (new JsonSerializer())->serialize($written); // what the client does; it must not throw

        $gateway->documents = [];
        $writer->record('user', 2, AuditEvent::UPDATE, self::deeper($changes), self::deeper($attributes));

        self::assertSame([], $gateway->documents);
        self::assertStringContainsString('512', implode("\n", $this->logs));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function deeper(array $values): array
    {
        return array_map(static fn (mixed $v): mixed => match (true) {
            $v instanceof Change => new Change(null, [$v->new]),
            $v instanceof NestsByArrays => new NestsByArrays($v->levels + 1),
            default => [$v],
        }, $values);
    }

    /** @return array<mixed> */
    private static function nest(int $levels): array
    {
        $value = 'leaf';

        for ($i = 0; $i < $levels; ++$i) {
            $value = [$value];
        }

        return $value;
    }

    private function assertOnlyPlainValues(mixed $value, string $path = 'document'): void
    {
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertOnlyPlainValues($item, $path.'.'.$key);
            }

            return;
        }

        if (\is_object($value)) {
            self::assertSame(\stdClass::class, $value::class, sprintf('%s is a %s', $path, $value::class));

            foreach (get_object_vars($value) as $key => $item) {
                $this->assertOnlyPlainValues($item, $path.'.'.$key);
            }

            return;
        }

        self::assertTrue($value === null || \is_scalar($value), sprintf('%s is a %s', $path, get_debug_type($value)));
    }

    /**
     * @return MessageBusInterface&object{sent: list<Envelope>}
     */
    private function bus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->sent[] = Envelope::wrap($message, $stamps);
            }
        };
    }

    private function writer(TransportInterface $transport, ?ChangeRedactor $redactor = null, bool $listen = false, ?object $replaceWith = null): AuditWriter
    {
        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /** @param list<string> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->logs[] = strtr((string) $message, array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', array_combine(array_map(static fn (string|int $k): string => '{'.$k.'}', array_keys($context)), $context)));
            }
        };

        $events = &$this->events;
        $dispatcher = new class($events, $listen, $replaceWith) implements EventDispatcherInterface {
            /** @param list<object> $events */
            public function __construct(private array &$events, private readonly bool $listen, private readonly ?object $replaceWith)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                if ($event instanceof RecordCreatedEvent && $this->replaceWith !== null) {
                    $event->setRecord($event->getRecord()->withChanges($event->getRecord()->changes + ['added' => $this->replaceWith]));
                } elseif ($event instanceof RecordCreatedEvent && $this->listen) {
                    $event->setRecord($event->getRecord());
                }

                return $event;
            }
        };

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Log, $logger, $dispatcher, null, $redactor);
    }
}

final class HoldsAPrivateSecret
{
    public string $status = 'active';
    private string $password = 'HANDED_SECRET_5e1b';

    public function password(): string
    {
        return $this->password;
    }
}

final class HoldsAPublicPassword
{
    public string $status = 'active';
    public string $password = 'HANDED_SECRET_5e1b';
    public ?string $note = 'set';
}

final class ReadsOnly
{
    public function __construct(public readonly string $status = 'active')
    {
    }
}

final class SerialisesAndHoldsASecret implements \JsonSerializable
{
    private string $password = 'HANDED_SECRET_5e1b';

    public function jsonSerialize(): array
    {
        return ['length' => \strlen($this->password)];
    }
}

final class AnswersDifferentlyEachTime implements \JsonSerializable
{
    private int $asked = 0;

    public function jsonSerialize(): array
    {
        return ++$this->asked === 1 ? ['status' => 'active'] : ['status' => 'active', 'password' => 'HANDED_SECRET_5e1b'];
    }
}

final class CountsItsAnswers implements \JsonSerializable
{
    public static int $asked = 0;

    public function jsonSerialize(): array
    {
        ++self::$asked;

        return ['status' => 'active'];
    }
}

final class Hops implements \JsonSerializable
{
    public function __construct(private readonly int $left)
    {
    }

    public function jsonSerialize(): mixed
    {
        return $this->left <= 1 ? ['end' => true] : new self($this->left - 1);
    }
}

final class NestsByArrays implements \JsonSerializable
{
    public function __construct(public readonly int $levels)
    {
    }

    public function jsonSerialize(): mixed
    {
        // Each object answers with an array holding the next: one level per object, as
        // json_encode counts it.
        return $this->levels <= 1 ? ['leaf'] : [new self($this->levels - 1)];
    }
}

enum Suit: string
{
    case Hearts = 'H';
}

enum Bare
{
    case One;
}

final class HoldsADate
{
    public Suit $kind = Suit::Hearts;

    public function __construct(public \DateTimeImmutable $at)
    {
    }
}

final class SerialisesADate implements \JsonSerializable
{
    public function __construct(private readonly \DateTimeImmutable $at)
    {
    }

    public function jsonSerialize(): array
    {
        return ['at' => $this->at, 'kind' => Suit::Hearts];
    }
}

final class ADateWithSecrets extends \DateTimeImmutable
{
    public string $visible = 'shown';
    public string $password = 'HANDED_SECRET_5e1b';
    private string $token = 'HANDED_SECRET_5e1b';

    public function token(): string
    {
        return $this->token;
    }
}

final class ADateThatSerialisesItself extends \DateTimeImmutable implements \JsonSerializable
{
    public function jsonSerialize(): array
    {
        return ['at' => 'its own'];
    }
}
