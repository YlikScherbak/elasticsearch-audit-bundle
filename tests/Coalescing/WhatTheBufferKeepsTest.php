<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use PHPUnit\Framework\TestCase;

/**
 * The buffer, asked directly rather than through a frame.
 *
 * Coalescing exists to turn the four saves one business operation makes into the one
 * record a person reads, and every branch here decides what survives that: which
 * records are merged, which are let past immediately, which are dropped as noise, and
 * which are thrown away entirely because the operation they belonged to was refused.
 *
 * Getting any of those wrong is quiet. A record dropped that should have been kept
 * leaves a gap nobody can see; one kept that should have been dropped is noise that
 * makes the rest harder to read. Neither fails anything.
 */
final class WhatTheBufferKeepsTest extends TestCase
{
    public function testARefusedOperationLeavesNothingBehindForTheNextOne(): void
    {
        // A poisoned buffer is one whose operation failed. Closing it must release
        // nothing — and must also forget what it held, because the next frame in the
        // same worker would otherwise open onto somebody else's half-written history.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($this->update('order', 1, 'new', 'paid'));
        $buffer->refuse();

        self::assertSame([], $buffer->close());

        $buffer->open();

        self::assertSame([], $buffer->close(), 'the refused operation came back in the next frame');
    }

    public function testARefusedOperationStopsTakingRecordsAtAll(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->refuse();

        self::assertSame([], $buffer->hold($this->update('order', 1, 'new', 'paid')));
        // Not merely "answers with nothing": it must not be holding it either. A record
        // taken in after the refusal would sit in the buffer with nothing left to
        // release it, and the next frame in this worker is what would find it.
        self::assertSame(0, $buffer->count());
        self::assertSame([], $buffer->close());
    }

    public function testAComparatorThatThrowsWhileAnObjectIsDeletedLosesNeitherRecord(): void
    {
        // finalizeSafely() answers null when a comparator throws, and the deletion path
        // builds its output from that answer. Handed straight through, the null travels
        // as a record: the writer is asked to publish nothing, and both the update that
        // was held and the deletion behind it are lost - under a failure policy whose
        // whole purpose is that the operation carries on.
        $broken = new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('a comparator written elsewhere');
            }
        };

        $buffer = new FrameBuffer(new ValueComparator([$broken]));
        $buffer->open();
        $buffer->hold($this->update('order', 1, 'new', 'paid'));

        $out = $buffer->hold(new AuditRecord('order', 1, AuditEvent::REMOVE));

        self::assertContainsOnlyInstancesOf(AuditRecord::class, $out, 'a null was handed on as though it were a record');
        self::assertNotSame([], $buffer->takeFinalizeFailures(), 'and the failure was not kept for the writer');
    }

    public function testADeletedObjectSendsWhatWasHeldAboutItAndThenTheDeletion(): void
    {
        // Order matters and is the whole point: the update that was being held is what
        // the object looked like before it went, so it goes out first. Losing it would
        // leave a history that says an object was deleted and never says what it was.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($this->update('order', 1, 'new', 'paid'));

        $out = $buffer->hold(new AuditRecord('order', 1, AuditEvent::REMOVE));

        self::assertSame([AuditEvent::UPDATE, AuditEvent::REMOVE], array_map(static fn (AuditRecord $r): string => $r->event, $out));
        self::assertSame([], $buffer->close(), 'and nothing was left holding');
    }

    public function testADeletionOfSomethingNothingWasHeldAboutIsJustTheDeletion(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();

        $out = $buffer->hold(new AuditRecord('order', 1, AuditEvent::REMOVE));

        self::assertCount(1, $out);
        self::assertSame(AuditEvent::REMOVE, $out[0]->event);
    }

    public function testASecondActorSendsTheFirstOnesWorkOutRatherThanMergingIntoIt(): void
    {
        // Merging across actors is the one thing coalescing must not do: the record
        // would say one person made a change another one made. So the held record goes
        // out as it stands and the new actor starts a fresh one.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($this->update('order', 1, 'new', 'paid', 'alice'));

        $out = $buffer->hold($this->update('order', 1, 'paid', 'shipped', 'bob'));

        self::assertCount(1, $out, 'the first actor\'s record was not sent out');
        self::assertSame('alice', $out[0]->actor);

        $closed = $buffer->close();

        self::assertCount(1, $closed);
        self::assertSame('bob', $closed[0]->actor);
    }

    public function testTheValveCountsWhatIsStagedAsWellAsWhatIsHeld(): void
    {
        // One object and alternating actors stages a record at every boundary without
        // ever holding more than one, which is how ten thousand records once fitted
        // under max_held: 1. The limit is on what the frame is keeping from the log,
        // and a staged record is exactly that.
        $buffer = new FrameBuffer(maxHeld: 2, throwOnOverflow: true);
        $buffer->open();

        $buffer->hold($this->update('order', 1, 'a', 'b', 'alice'));
        $buffer->hold($this->update('order', 1, 'b', 'c', 'bob'));

        $this->expectException(FrameOverflowException::class);

        $buffer->hold($this->update('order', 1, 'c', 'd', 'carol'));
    }

    public function testTheValveHoldsExactlyAsManyAsItSays(): void
    {
        // The other side of the same edge: two is two, not one.
        $buffer = new FrameBuffer(maxHeld: 2, throwOnOverflow: true);
        $buffer->open();

        $buffer->hold($this->update('order', 1, 'a', 'b', 'alice'));
        $buffer->hold($this->update('order', 2, 'a', 'b', 'alice'));

        self::assertCount(2, $buffer->close());
    }

    public function testAFieldThatChangedAndChangedBackIsNotAChange(): void
    {
        // The noise coalescing exists to remove: a quantity that goes 1000 → 1040 → 1000
        // across one operation did not change, and a record saying it did is a person
        // chasing something that never happened.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($this->update('stock', 1, 1000, 1040));
        $buffer->hold($this->update('stock', 1, 1040, 1000));

        self::assertSame([], $buffer->close(), 'a round trip was recorded as a change');
    }

    public function testFreeFormDataIsContentInItsOwnRight(): void
    {
        // Not every change is an old/new pair — a record can carry a payload the
        // application put there. Nothing here can say whether it "changed", so it counts
        // as something to record rather than as noise to drop.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['note' => ['anything' => 'at all']]));

        $closed = $buffer->close();

        self::assertCount(1, $closed, 'a record carrying only free-form data was dropped as unchanged');
        self::assertSame(['note' => ['anything' => 'at all']], $closed[0]->changes);
    }

    public function testFreeFormDataFromTheSecondSaveStands(): void
    {
        // Merged rather than paired: there is no "old" to keep, so the latest value is
        // the one the merged record carries.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['note' => ['step' => 'one']]));
        $buffer->hold(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['note' => ['step' => 'two']]));

        $closed = $buffer->close();

        self::assertCount(1, $closed);
        self::assertSame(['note' => ['step' => 'two']], $closed[0]->changes);
    }

    public function testADeletionDoesNotCarryAnEmptyRecordInFrontOfIt(): void
    {
        // An update in which nothing actually moved finalizes to nothing, and "nothing"
        // is not a record. Handed on as one it reaches the writer as a null, and the
        // deletion behind it goes out with a hole in front of it.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($this->update('order', 1, 'paid', 'paid'));

        $out = $buffer->hold(new AuditRecord('order', 1, AuditEvent::REMOVE));

        self::assertCount(1, $out, 'something that was not a record travelled with the deletion');
        self::assertSame(AuditEvent::REMOVE, $out[0]->event);
    }

    public function testASecondActorDoesNotInheritAnEmptyRecordEither(): void
    {
        // The same hole on the other boundary: the first actor's held record turns out
        // to say nothing, so there is nothing to send out when the second one arrives.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($this->update('order', 1, 'paid', 'paid', 'alice'));

        $out = $buffer->hold($this->update('order', 1, 'paid', 'shipped', 'bob'));

        self::assertSame([], $out);
    }

    public function testAFieldThatMovedAndCameBackWithinOneActorsWorkIsStillNoise(): void
    {
        // The round trip, but starting after somebody else's record was released. The
        // second actor's first save is what marks the field as having moved; without
        // that mark the merged record ends where it started and is kept as "context"
        // rather than dropped — a record saying a status changed from paid to paid.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($this->update('order', 1, 'new', 'paid', 'alice'));

        $released = $buffer->hold($this->update('order', 1, 'paid', 'shipped', 'bob'));

        self::assertCount(1, $released, 'the record from the first actor should have gone out at the boundary');

        $buffer->hold($this->update('order', 1, 'shipped', 'paid', 'bob'));

        self::assertSame([], $buffer->close(), 'bob went there and came back, and it was recorded as a change');
    }

    public function testAFreeFormFieldDoesNotStopTheFieldsAfterItFromMerging(): void
    {
        // Free-form data is handled by a shortcut in the middle of the merge, and a
        // shortcut that leaves the loop instead of skipping one turn takes every field
        // after it with it. The record still gets written, so nothing fails — it is
        // simply missing half of what changed, which is the quietest way an audit trail
        // can be wrong.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: [
            'note' => ['step' => 'one'],
            'status' => new Change('new', 'paid'),
        ]));
        $buffer->hold(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: [
            'note' => ['step' => 'two'],
            'status' => new Change('paid', 'shipped'),
        ]));

        $closed = $buffer->close();

        self::assertCount(1, $closed);
        self::assertSame(['step' => 'two'], $closed[0]->changes['note'] ?? null);

        $status = $closed[0]->changes['status'] ?? null;

        self::assertInstanceOf(Change::class, $status, 'the field after the free-form one was dropped from the merge');
        self::assertSame('new', $status->old, 'and the merged record forgot where the operation started');
        self::assertSame('shipped', $status->new);
    }

    public function testDroppingARoundTripDoesNotDropWhatCameAfterIt(): void
    {
        // The same shape one step later: a field that went somewhere and came back is
        // removed from the record, and removing it must not remove the field beside it —
        // which is the one thing that actually happened in this operation.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($this->update('order', 1, 'new', 'paid', 'alice'));
        $buffer->hold(new AuditRecord('order', 1, AuditEvent::UPDATE, actor: 'bob', changes: [
            'status' => new Change('paid', 'shipped'),
            'total' => new Change(10, 12),
        ]));
        $buffer->hold(new AuditRecord('order', 1, AuditEvent::UPDATE, actor: 'bob', changes: [
            'status' => new Change('shipped', 'paid'),
        ]));

        $closed = $buffer->close();

        self::assertCount(1, $closed, 'the second actor recorded nothing, though the total changed');
        self::assertSame(['total'], array_keys($closed[0]->changes), 'the round trip was kept, or the real change was lost with it');
    }

    private function update(string $type, int $id, mixed $old, mixed $new, string $actor = 'alice'): AuditRecord
    {
        return new AuditRecord($type, $id, AuditEvent::UPDATE, actor: $actor, changes: ['status' => new Change($old, $new)]);
    }
}
