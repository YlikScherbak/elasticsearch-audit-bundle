<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use PHPUnit\Framework\TestCase;

final class FrameBufferTest extends TestCase
{
    public function testAChangeThatCameBackToItsStartIsNotRecorded(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();

        self::assertSame([], $buffer->hold(self::update(1, ['fact' => new Change(1000, 1040)])));
        self::assertSame([], $buffer->hold(self::update(1, ['fact' => new Change(1040, 1000)])));

        self::assertSame([], $buffer->close(), 'the mirror pair cancels out');
    }

    public function testSeveralStepsBecomeOneChangeFromFirstOldToLastNew(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1000, 1040), 'reserve' => new Change(5, 6)]));
        $buffer->hold(self::update(1, ['fact' => new Change(1040, 995)]));

        $released = $buffer->close();

        self::assertCount(1, $released);
        self::assertSame(['fact' => ['old' => 1000, 'new' => 995], 'reserve' => ['old' => 5, 'new' => 6]], $released[0]->toDocument()['changes']);
    }

    public function testAFieldThatNeverDifferedIsKeptAsTheRecordsContext(): void
    {
        // What #[Auditable(alwaysRecord: ['status'])] produces: the same value on both
        // sides, in every step, to give the change around it a context.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1000, 1040), 'status' => new Change('open', 'open')]));
        $buffer->hold(self::update(1, ['fact' => new Change(1040, 995), 'status' => new Change('open', 'open')]));

        [$record] = $buffer->close();

        self::assertSame([
            'fact' => ['old' => 1000, 'new' => 995],
            'status' => ['old' => 'open', 'new' => 'open'],
        ], $record->toDocument()['changes'], 'the context survives coalescing; only a field that moved and came back is noise');
    }

    public function testContextAloneIsNoReasonToRecordAnUpdate(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1000, 1040), 'status' => new Change('open', 'open')]));
        $buffer->hold(self::update(1, ['fact' => new Change(1040, 1000), 'status' => new Change('open', 'open')]));

        self::assertSame([], $buffer->close(), 'nothing moved in the end, so there is nothing to say');
    }

    public function testAFieldThatEndsSomewhereElseIsRecordedEvenIfNoSingleStepMovedIt(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(self::update(1, ['status' => new Change('open', 'open')]));
        $buffer->hold(self::update(1, ['status' => new Change('closed', 'closed')]));

        [$record] = $buffer->close();

        self::assertSame(['old' => 'open', 'new' => 'closed'], $record->toDocument()['changes']['status']);
    }

    public function testObjectsAreHeldSeparatelyAndReleasedInFirstSeenOrder(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(self::update(2, ['fact' => new Change(1, 2)]));
        $buffer->hold(self::update(1, ['fact' => new Change(1, 2)]));
        $buffer->hold(self::update(2, ['fact' => new Change(2, 3)]));

        self::assertSame([2, 1], array_map(static fn (AuditRecord $r) => $r->objectId, $buffer->close()));
    }

    public function testACreateFollowedByUpdatesStaysOneCreateWithFinalValues(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(new AuditRecord('stock', 1, AuditEvent::CREATE, self::at(), 'system', ['fact' => new Change(null, 10)], id: 'first'));
        $buffer->hold(self::update(1, ['fact' => new Change(10, 12)]));

        [$record] = $buffer->close();

        self::assertSame(AuditEvent::CREATE, $record->event);
        self::assertSame('first', $record->id);
        self::assertSame(['old' => null, 'new' => 12], $record->toDocument()['changes']['fact']);
    }

    public function testACreateIsKeptEvenWhenNothingChangedAfterwards(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(new AuditRecord('stock', 1, AuditEvent::CREATE, self::at(), 'system', ['fact' => new Change(null, null)]));

        self::assertCount(1, $buffer->close(), 'a create is a fact in itself');
    }

    public function testARemoveFlushesTheHeldRecordFirstAndGoesOutImmediately(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1, 2)]));

        $out = $buffer->hold(new AuditRecord('stock', 1, AuditEvent::REMOVE, self::at(), 'system'));

        self::assertSame([AuditEvent::UPDATE, AuditEvent::REMOVE], array_map(static fn (AuditRecord $r) => $r->event, $out));
        self::assertSame([], $buffer->close(), 'nothing is left for that object');
    }

    public function testNestedFramesReleaseOnlyWhenTheOutermostCloses(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1, 2)]));

        self::assertNull($buffer->close(), 'inner close: still open');
        self::assertTrue($buffer->isOpen());
        self::assertCount(1, $buffer->close());
        self::assertFalse($buffer->isOpen());
    }

    public function testClosingANeverOpenedFrameIsHarmless(): void
    {
        self::assertNull((new FrameBuffer())->close());
    }

    public function testResetDropsEverythingWithoutReleasing(): void
    {
        $buffer = new FrameBuffer();

        self::assertFalse($buffer->reset(), 'nothing to reset');

        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1, 2)]));

        self::assertTrue($buffer->reset());
        self::assertFalse($buffer->isOpen());
        self::assertSame(0, $buffer->count());
    }

    public function testOnlyConfiguredTypesAreAccepted(): void
    {
        $buffer = new FrameBuffer(objectTypes: ['stock']);

        self::assertFalse($buffer->accepts('stock'), 'not while closed');

        $buffer->open();

        self::assertTrue($buffer->accepts('stock'));
        self::assertFalse($buffer->accepts('order'));
    }

    public function testAFullBufferReleasesWhatItHoldsAndKeepsGoing(): void
    {
        $buffer = new FrameBuffer(maxHeld: 2);
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1, 2)]));
        $buffer->hold(self::update(2, ['fact' => new Change(1, 2)]));

        $released = $buffer->hold(self::update(3, ['fact' => new Change(1, 2)]));

        self::assertSame([1, 2], array_map(static fn (AuditRecord $r) => $r->objectId, $released));
        self::assertSame(1, $buffer->count());
    }

    public function testFreeFormChangesTakeTheLatestValueAndAttributesMerge(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(self::update(1, ['note' => 'first', 'fact' => new Change(1, 2)])->withAttributes(['warehouseId' => 1]));
        $buffer->hold(self::update(1, ['note' => 'second'])->withAttributes(['origin' => 'move']));

        [$record] = $buffer->close();

        self::assertSame('second', $record->changes['note']);
        self::assertSame(['warehouseId' => 1, 'origin' => 'move'], $record->attributes);
    }

    public function testApplicationComparatorsDecideWhatCountsAsUnchanged(): void
    {
        $buffer = new FrameBuffer(new ValueComparator([new NumericNullAsZeroComparator(['fact'])]));
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(null, 0), 'name' => new Change('a', 'b')]));
        $buffer->hold(self::update(2, ['fact' => new Change('-', '0.0')]));

        $released = $buffer->close();

        self::assertCount(1, $released, 'object 2 changed nothing in the comparator\'s eyes');
        self::assertSame(['name' => ['old' => 'a', 'new' => 'b']], $released[0]->toDocument()['changes']);
    }

    public function testPairsGivenAsArraysAreMergedLikeChangeObjects(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => ['old' => 1, 'new' => 2]]));
        $buffer->hold(self::update(1, ['fact' => ['old' => 2, 'new' => 3]]));

        self::assertSame(['old' => 1, 'new' => 3], $buffer->close()[0]->toDocument()['changes']['fact']);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function update(int $id, array $changes): AuditRecord
    {
        return new AuditRecord('stock', $id, AuditEvent::UPDATE, self::at(), 'system', $changes);
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-27 10:00:00', new \DateTimeZone('UTC'));
    }
}
