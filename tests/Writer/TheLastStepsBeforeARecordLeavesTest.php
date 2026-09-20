<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MergedRecordEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ScopedEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
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
 * The order of the last few steps, and what each of them is allowed to undo.
 *
 * A record leaving the writer has passed through four hands: the enrichers that run per
 * step, the ones that run on what a frame merged, the redactor, and whatever the
 * application listens with. Each of those is a place where a field can appear, and the
 * redactor is the one that has to be last — the whole of `redact.fields` is worth
 * exactly as much as that ordering.
 *
 * What this file asks is not "does each part work" — the other writer tests do that —
 * but that a part skipped stays skipped and a part that runs runs once. Both are
 * `continue`s and a `??`, which is the kind of thing that gets rewritten during a
 * refactoring by somebody who reads them as tidying.
 */
final class TheLastStepsBeforeARecordLeavesTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testAnEnricherScopedElsewhereIsNotAskedAboutThisRecord(): void
    {
        // An enricher that named its object types has already said no, and the index it
        // does not write to was mapped without its field: running it anyway puts that
        // field in a document the mapping has no place for, where `dynamic: false`
        // stores it unsearchable.
        //
        // Two enrichers, because skipping one must not end the walk: the unscoped one
        // after it is what says the loop went on rather than stopped.
        $writer = $this->writer([
            self::scoped('invoice', 'onlyForInvoices'),
            self::stamping('forEverything'),
        ]);

        $writer->write(new AuditRecord('order', 1, 'update', changes: ['status' => new Change('a', 'b')]));

        // Attributes ride at the top level of the document, which is what an enricher's
        // mapping declares.
        $document = $this->document();

        self::assertArrayNotHasKey('onlyForInvoices', $document, 'an enricher for invoices enriched an order');
        self::assertArrayHasKey('forEverything', $document, 'and skipping it ended the walk, so the next one never ran');
    }

    public function testAMergedEnricherScopedElsewhereIsNotAskedEither(): void
    {
        // The same question on the other loop. These run in prepare(), on the record
        // that is about to be stored, and the scope means the same thing there.
        $writer = $this->writer([
            self::scopedMerged('invoice', 'onlyForInvoices'),
            self::merged('forEverything'),
        ]);

        $writer->write(new AuditRecord('order', 1, 'update', changes: ['status' => new Change('a', 'b')]));

        $document = $this->document();

        self::assertArrayNotHasKey('onlyForInvoices', $document, 'a merged enricher for invoices enriched an order');
        self::assertArrayHasKey('forEverything', $document, 'and skipping it ended the walk, so the next one never ran');
    }

    public function testAMergedEnricherIsAskedOnceAndOnTheWayOut(): void
    {
        // A merged enricher is skipped by the per-step loop on purpose: what it says is
        // about the record that will be stored, not about the step being recorded. Run
        // in both places it would be asked twice per record — and twice is not harmless
        // for an enricher that counts, appends, or calls something.
        //
        // Skipped, and the walk goes on: the ordinary enricher behind it is registered
        // second on purpose. "Stop at the first merged one" reads like the same thing
        // and silently drops everything after it — an application registers these in
        // whatever order the container hands them over.
        $calls = 0;
        $writer = $this->writer([self::counting($calls), self::stamping('afterAMergedOne')]);

        $writer->write(new AuditRecord('order', 1, 'update', changes: ['status' => new Change('a', 'b')]));

        self::assertSame(1, $calls, 'the merged enricher ran in both loops rather than only the one it is for');
        self::assertArrayHasKey('afterAMergedOne', $this->document(), 'the merged one ended the walk instead of standing aside from it');
    }

    public function testARecordAListenerReplacedIsRedactedBeforeItLeaves(): void
    {
        // A listener may hand back a record it built itself — reaching for the entity
        // again to add a field is the ordinary reason — and what it reaches for has not
        // been through the redactor. A redactor that is not the last word is not a
        // policy, so this is what the document must say whichever road the record took.
        //
        // **Two passes cover this one outcome, and only their pair is observable.**
        // RecordCreatedEvent::setRecord() redacts what it is handed, so the listener
        // after this one cannot read the value either; the writer redacts again on the
        // way to the transport. Remove one and this test still passes, because the
        // other one does the work — measured, both ways round. Remove both and it
        // fails. So the assertion below is about the outcome and not about either
        // line, and neither line has a test that can see it alone. The second pass is
        // documented as an equivalent mutant in infection.json5 for that reason, which
        // is a statement about today's callers: a road to the transport that does not
        // pass setRecord() would make it live again.
        $writer = $this->writer(
            redactor: new ChangeRedactor(['secret']),
            events: self::listener(static function (object $event): void {
                if ($event instanceof RecordCreatedEvent) {
                    $event->setRecord($event->getRecord()->withChanges(['secret' => new Change('old-one', 'the-new-password')]));
                }
            }),
        );

        $writer->write(new AuditRecord('order', 1, 'update', changes: ['status' => new Change('a', 'b')]));

        $changes = $this->document()['changes'] ?? [];

        self::assertSame('***', $changes['secret']['new'] ?? null, 'what the listener put back went to the transport unredacted');
        self::assertSame('***', $changes['secret']['old'] ?? null);
    }

    private static function scoped(string $objectType, string $attribute): AuditEnricherInterface
    {
        return new class($objectType, $attribute) implements AuditEnricherInterface, ScopedEnricherInterface {
            public function __construct(private readonly string $objectType, private readonly string $attribute)
            {
            }

            public function objectTypes(): array
            {
                return [$this->objectType];
            }

            public function supports(AuditRecord $record): bool
            {
                // Deliberately yes to everything: the scope is what must keep this away
                // from the record, and a supports() that also said no would hide which
                // of the two did it.
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes([$this->attribute => true]);
            }

            public function mapping(): array
            {
                return [$this->attribute => ['type' => 'boolean']];
            }
        };
    }

    private static function scopedMerged(string $objectType, string $attribute): AuditEnricherInterface
    {
        return new class($objectType, $attribute) implements MergedRecordEnricherInterface, ScopedEnricherInterface {
            public function __construct(private readonly string $objectType, private readonly string $attribute)
            {
            }

            public function objectTypes(): array
            {
                return [$this->objectType];
            }

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes([$this->attribute => true]);
            }

            public function mapping(): array
            {
                return [$this->attribute => ['type' => 'boolean']];
            }
        };
    }

    private static function stamping(string $attribute): AuditEnricherInterface
    {
        return new class($attribute) implements AuditEnricherInterface {
            public function __construct(private readonly string $attribute)
            {
            }

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes([$this->attribute => true]);
            }

            public function mapping(): array
            {
                return [$this->attribute => ['type' => 'boolean']];
            }
        };
    }

    private static function merged(string $attribute): AuditEnricherInterface
    {
        return new class($attribute) implements MergedRecordEnricherInterface {
            public function __construct(private readonly string $attribute)
            {
            }

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes([$this->attribute => true]);
            }

            public function mapping(): array
            {
                return [$this->attribute => ['type' => 'boolean']];
            }
        };
    }

    private static function counting(int &$calls): AuditEnricherInterface
    {
        return new class($calls) implements MergedRecordEnricherInterface {
            public function __construct(private int &$calls)
            {
            }

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                ++$this->calls;

                return $record;
            }

            public function mapping(): array
            {
                return [];
            }
        };
    }

    private static function listener(callable $listener): EventDispatcherInterface
    {
        return new class($listener) implements EventDispatcherInterface {
            /** @var callable */
            private $listener;

            public function __construct(callable $listener)
            {
                $this->listener = $listener;
            }

            public function dispatch(object $event): object
            {
                ($this->listener)($event);

                return $event;
            }
        };
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    private function writer(iterable $enrichers = [], ?ChangeRedactor $redactor = null, ?EventDispatcherInterface $events = null): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter(
            $transport,
            $transport,
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'tests'),
            new FrozenClock(),
            $enrichers,
            FailurePolicy::Throw,
            null,
            $events,
            null,
            $redactor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $documents = $this->gateway->documents['audit_log'] ?? [];

        self::assertNotEmpty($documents);

        return $documents[array_key_last($documents)];
    }
}
