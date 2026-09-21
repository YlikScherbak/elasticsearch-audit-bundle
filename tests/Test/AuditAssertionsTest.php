<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Test;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Test\AuditAssertions;
use Borsche\ElasticsearchAuditBundle\Test\AuditCollector;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The assertions, and what they say when they fail.
 *
 * Which is the whole reason they exist rather than being a line of assertCount: "expected
 * 1, got 0" leaves a person choosing between four bugs, three of which are not in the code
 * under test — nothing was recorded, something else was, a listener vetoed it, or the frame
 * is still open. So the failures are asserted here as carefully as the successes.
 */
final class AuditAssertionsTest extends TestCase
{
    use AuditAssertions;

    private AuditCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new AuditCollector();
    }

    protected function auditCollector(): AuditCollector
    {
        return $this->collector;
    }

    public function testItFindsTheRecordAndHandsItBack(): void
    {
        $this->writer()->record('order', 42, 'update', ['status' => ['old' => 'new', 'new' => 'paid']]);

        $record = $this->assertAudited('order', 42, 'update');

        self::assertSame('paid', $record->entry()->changes['status']['new']);
    }

    public function testOneMeansOneAndTwoIsASeparateQuestion(): void
    {
        // A duplicated record is a defect this bundle spends whole subsystems
        // preventing; an assertion that passes on two of them would not notice.
        $writer = $this->writer();
        $writer->record('order', 42, 'update');
        $writer->record('order', 42, 'update');

        self::assertSame(
            'Expected exactly one audit record for order#42 (update). Written: update order#42 (audit_log), update order#42 (audit_log).',
            $this->failureOf(fn () => $this->assertAudited('order', 42, 'update')),
        );

        $found = $this->assertAuditedTimes(2, 'order', 42);

        self::assertCount(2, $found);
    }

    public function testNothingRecordedSaysSo(): void
    {
        self::assertSame('Expected exactly one audit record for order#42. Nothing was written.', $this->failureOf(fn () => $this->assertAudited('order', 42)));

        $this->assertNothingAudited();
        $this->assertNotAudited('order', 42);
    }

    public function testSomethingElseRecordedIsNamed(): void
    {
        // The second of the four bugs: history was written, just not this. The message
        // lists what was, because "expected 1, got 0" sends somebody to the wrong file.
        $this->writer()->record('order', 43, 'delete');

        self::assertSame(
            'Expected exactly one audit record for order#42 (update). Written: delete order#43 (audit_log).',
            $this->failureOf(fn () => $this->assertAudited('order', 42, 'update')),
        );

        self::assertSame(
            'Expected no audit records at all. Written: delete order#43 (audit_log).',
            $this->failureOf(fn () => $this->assertNothingAudited()),
        );
    }

    public function testAVetoIsAssertedOnWhereItCanBeSeen(): void
    {
        // The third: the record was built and stopped on purpose. It reaches no
        // transport and nothing is logged, so this is the only place to look.
        $this->writer(self::vetoing())->record('order', 42, 'update');

        $vetoed = $this->assertAuditVetoed('order', 42, 'update');

        self::assertSame('update', $vetoed->entry()->event);
        $this->assertNotAudited('order', 42);

        self::assertSame(
            'Expected exactly one audit record for order#42 (update). Nothing was written. Vetoed by a listener: update order#42 ().',
            $this->failureOf(fn () => $this->assertAudited('order', 42, 'update')),
        );
    }

    public function testAnOpenFrameIsNamedRatherThanClosed(): void
    {
        // The fourth, and the one that has to be a message rather than a helpful
        // assertion: closing the frame to look would be the test changing what it
        // measures.
        $buffer = new FrameBuffer();
        $this->collector = new AuditCollector($buffer);

        $buffer->open();
        $this->writer(null, $buffer)->record('order', 42, 'update', ['status' => ['old' => 'new', 'new' => 'paid']]);

        self::assertSame(
            'Expected exactly one audit record for order#42. Nothing was written. 1 record(s) are held by a frame that is still open, and reach the transport when it closes.',
            $this->failureOf(fn () => $this->assertAudited('order', 42)),
        );
    }

    public function testAMissingVetoSaysWhatThereWasInstead(): void
    {
        $this->writer()->record('order', 42, 'update');

        self::assertSame(
            'Expected exactly one vetoed audit record for order#42. Written: update order#42 (audit_log).',
            $this->failureOf(fn () => $this->assertAuditVetoed('order', 42)),
        );
    }

    /**
     * The message a failing assertion produced, without the assertion failing this test.
     */
    private function failureOf(callable $assertion): string
    {
        try {
            $assertion();
        } catch (ExpectationFailedException $e) {
            // PHPUnit appends its own comparison to the message it was given; what is
            // being asserted here is the sentence this trait wrote.
            return trim(explode("\n", $e->getMessage())[0]);
        }

        self::fail('the assertion passed where it should have failed');
    }

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

    private function writer(?EventDispatcherInterface $events = null, ?FrameBuffer $buffer = null): AuditWriter
    {
        return new AuditWriter(
            $this->collector,
            $this->collector,
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'system'),
            new FrozenClock(),
            events: $events,
            frame: $buffer,
        );
    }
}
