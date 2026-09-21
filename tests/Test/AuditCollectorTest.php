<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Test;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Test\AuditCollector;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The transport a test uses, tested.
 *
 * What it is for is the thing a hand-written fake gets wrong: it sits at the very last
 * step, so everything before it — completion, enrichment, coalescing, redaction,
 * routing — really happens, and what a test asserts on is the document that would have
 * been stored. The tests here are mostly about the two questions that are not "what was
 * written": a record a listener vetoed, which reaches no transport at all, and records
 * an open frame is still holding, which is the reason a correct test looks empty.
 */
final class AuditCollectorTest extends TestCase
{
    public function testItKeepsWhatWouldHaveBeenStored(): void
    {
        $collector = new AuditCollector();

        $this->writer($collector)->record('order', 42, 'update', ['status' => new Change('new', 'paid')], ['salesType' => 3]);

        self::assertCount(1, $collector->written());

        $record = $collector->written()[0];

        self::assertSame('audit_log', $record->index);
        self::assertNotNull($record->id, 'the id the transport was handed is the document id');

        // Read back through the model the reader returns, which is what lets a test and
        // the code that queries the history agree about what a field is called.
        $entry = $record->entry();

        self::assertSame('order', $entry->objectType);
        self::assertSame('42', (string) $entry->objectId);
        self::assertSame('update', $entry->event);
        self::assertSame('system', $entry->actor);
        self::assertSame(['old' => 'new', 'new' => 'paid'], $entry->changes['status']);
        self::assertSame(3, $entry->attribute('salesType'));
    }

    public function testItFindsTheRecordsAboutOneObject(): void
    {
        $collector = new AuditCollector();
        $writer = $this->writer($collector);

        $writer->record('order', 42, 'update');
        $writer->record('order', 43, 'update');
        $writer->record('order', 42, 'delete');
        $writer->record('user', 42, 'update');

        self::assertCount(4, $collector->written());
        self::assertCount(2, $collector->writtenFor('order', 42));
        self::assertCount(1, $collector->writtenFor('order', 42, 'delete'));
        self::assertCount(3, $collector->writtenFor('order'));
        self::assertSame([], $collector->writtenFor('order', 99));

        // An id is a keyword in the mapping unless the application said otherwise, so a
        // test should not have to know whether its entity handed over 42 or "42".
        self::assertCount(2, $collector->writtenFor('order', '42'));
    }

    public function testABatchIsCollectedRecordByRecord(): void
    {
        $collector = new AuditCollector();

        $this->writer($collector)->writeAll([
            new AuditRecord('order', 1, 'update'),
            new AuditRecord('order', 2, 'update'),
        ]);

        self::assertCount(2, $collector->written());
        self::assertSame(['audit_log', 'audit_log'], array_column($collector->written(), 'index'));
    }

    public function testARecordAListenerVetoedIsSomewhereToLook(): void
    {
        // It reaches no transport and nothing is logged — a veto is a feature, not a
        // failure — so without this a test for one has nothing to assert on. The writer
        // says so after the dispatch, which is why this dispatcher only vetoes: a
        // collector listening for the event itself would be racing whoever vetoes last.
        $collector = new AuditCollector();

        $this->writer($collector, self::vetoing())->record('order', 42, 'update');

        self::assertSame([], $collector->written(), 'a vetoed record was written anyway');
        self::assertCount(1, $collector->vetoed());
        self::assertTrue($collector->vetoed()[0]->isAbout('order', 42, 'update'));
        self::assertStringContainsString('Vetoed by a listener', $collector->explain());
    }

    public function testARecordNobodyVetoedIsNotReportedAsVetoed(): void
    {
        $collector = new AuditCollector();

        $this->writer($collector, self::watching())->record('order', 42, 'update');

        self::assertCount(1, $collector->written());
        self::assertSame([], $collector->vetoed());
    }

    public function testAnOpenFrameIsTheAnswerToWhyNothingWasWritten(): void
    {
        // The failure this exists to prevent: a test coalesces, asserts before closing
        // the frame, finds nothing and starts looking for a bug in the application.
        // Closing the frame here to be helpful would be the assertion changing what it
        // measures — closing it is the behaviour under test.
        $buffer = new FrameBuffer();
        $collector = new AuditCollector($buffer);
        $writer = $this->writer($collector, null, $buffer);

        $buffer->open();
        $writer->record('order', 42, 'update', ['status' => new Change('new', 'paid')]);

        self::assertSame([], $collector->written());
        self::assertSame(1, $collector->held());
        self::assertStringContainsString('held by a frame that is still open', $collector->explain());

        $writer->writeManyCompleted($buffer->close() ?? []);

        self::assertCount(1, $collector->written());
        self::assertSame(0, $collector->held());
    }

    public function testItSaysWhetherAFieldIsRedactedRatherThanLeavingATestToGuess(): void
    {
        // A redacted value is stored as the placeholder, and "***" cannot tell a rule
        // that worked from an application that wrote three asterisks. The question is
        // answered by the same object that did the redacting.
        $redactor = new ChangeRedactor(['password', 'order.note']);
        $collector = new AuditCollector(null, $redactor);

        $this->writer($collector, null, null, $redactor)->record('user', 7, 'update', ['password' => new Change(null, 'hunter2')]);

        self::assertTrue($collector->redacts('user', 'password'));
        self::assertFalse($collector->redacts('user', 'note'), 'a rule scoped to another object type is not this one');
        self::assertTrue($collector->redacts('order', 'note'));

        $changes = $collector->written()[0]->entry()->changes;

        self::assertSame('***', $changes['password']['new']);
        self::assertStringNotContainsString('hunter2', json_encode($changes, \JSON_THROW_ON_ERROR));
    }

    public function testWithoutARedactorNothingIsClaimedToBeRedacted(): void
    {
        self::assertFalse((new AuditCollector())->redacts('user', 'password'));
    }

    public function testItForgetsWhenAskedTo(): void
    {
        $collector = new AuditCollector();

        $this->writer($collector, self::vetoing())->record('order', 42, 'update');
        $this->writer($collector)->record('order', 43, 'update');

        self::assertNotSame([], $collector->vetoed());
        self::assertNotSame([], $collector->written());

        $collector->reset();

        self::assertSame([], $collector->written());
        self::assertSame([], $collector->vetoed());
        self::assertSame('Nothing was written.', $collector->explain());
    }

    /**
     * A dispatcher that vetoes everything. Nothing hands the event to the collector: the
     * writer tells it once the dispatch is over, which is the only moment the verdict is
     * settled.
     */
    private static function vetoing(): EventDispatcherInterface
    {
        return new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof RecordCreatedEvent) {
                    $event->veto();
                }

                return $event;
            }
        };
    }

    /**
     * The same, without the veto.
     */
    private static function watching(): EventDispatcherInterface
    {
        return new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
    }

    private function writer(
        AuditCollector $collector,
        ?EventDispatcherInterface $events = null,
        ?FrameBuffer $buffer = null,
        ?ChangeRedactor $redactor = null,
    ): AuditWriter {
        return new AuditWriter(
            $collector,
            $collector,
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'system'),
            new FrozenClock(),
            events: $events,
            frame: $buffer,
            redactor: $redactor,
        );
    }
}
