<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
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

    public function testTwoObjectsWhoseNamesCollideAreStillTwoObjects(): void
    {
        // record() takes free-form strings for both halves, and the frame's identity
        // joined them with "|": type "a|b" with id "c" and type "a" with id "b|c" were
        // one key, and two objects' histories merged into one record inside a frame.
        $buffer = new FrameBuffer();
        $buffer->open();
        $at = new \DateTimeImmutable('2026-09-05 10:00:00', new \DateTimeZone('UTC'));
        $buffer->hold(new AuditRecord('a|b', 'c', AuditEvent::UPDATE, $at, 'tests', ['fact' => new Change(1, 2)]));
        $buffer->hold(new AuditRecord('a', 'b|c', AuditEvent::UPDATE, $at, 'tests', ['fact' => new Change(10, 20)]));

        $released = $buffer->close();

        self::assertCount(2, $released, 'two objects, two records');
        self::assertSame(['old' => 1, 'new' => 2], $released[0]->toDocument()['changes']['fact']);
        self::assertSame(['old' => 10, 'new' => 20], $released[1]->toDocument()['changes']['fact']);
    }

    public function testABrokenComparatorAtARemoveLosesNeitherRecord(): void
    {
        // release() already survives a comparator that throws — the record goes out
        // unfinalized and the mistake travels the failure policy. The terminal REMOVE
        // path took the held record out of the buffer and finalized it without that
        // net: one exception and both the update and the remove were gone, under a
        // policy whose whole point is that a business operation carries on.
        $broken = new class implements \Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('the comparator is broken');
            }
        };

        $buffer = new FrameBuffer(new ValueComparator([$broken]));
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1000, 1040)]));

        $out = $buffer->hold(new AuditRecord('stock', 1, AuditEvent::REMOVE));

        self::assertCount(2, $out, 'the held update and the remove both came out');
        self::assertSame(AuditEvent::UPDATE, $out[0]->event);
        self::assertSame(AuditEvent::REMOVE, $out[1]->event);
        self::assertCount(1, $buffer->takeFinalizeFailures(), 'and the broken comparator is reported, not swallowed');
    }

    public function testASingleComparatorLinkIsEnoughForABuffer(): void
    {
        // The buffer takes the interface, and one link legitimately answers null —
        // "no opinion". The buffer's own question at close ("did this field ever
        // differ") must then fall back to the plain comparison, like every other
        // consumer of the interface, instead of reading null as "never differed".
        $noOpinion = new class implements \Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                return null;
            }
        };

        $buffer = new FrameBuffer($noOpinion);
        $buffer->open();
        $buffer->hold(self::update(1, ['fact' => new Change(1000, 995), 'status' => new Change('open', 'open')]));

        [$record] = $buffer->close();

        self::assertSame(
            ['fact' => ['old' => 1000, 'new' => 995], 'status' => ['old' => 'open', 'new' => 'open']],
            $record->toDocument()['changes'],
            'the moved field is a change and the still one is context — decided by the fallback, not by a null read as an answer'
        );
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

    public function testAFrameCanRefuseToOverflowInsteadOfReleasingEarly(): void
    {
        // Releasing loses no record, but it ends the promise: an object let go early can
        // produce a second record for an operation whose net effect was nothing. A trail
        // read for that promise would rather be told.
        $buffer = new FrameBuffer(new ValueComparator(), maxHeld: 2, throwOnOverflow: true);
        $buffer->open();

        $buffer->hold(new AuditRecord('order', 1, 'update', changes: ['a' => new Change(1, 2)]));
        $buffer->hold(new AuditRecord('order', 2, 'update', changes: ['a' => new Change(1, 2)]));

        $this->expectException(FrameOverflowException::class);
        $this->expectExceptionMessage('coalescing.max_held');

        $buffer->hold(new AuditRecord('order', 3, 'update', changes: ['a' => new Change(1, 2)]));
    }
    public function testClosingEverythingAtOnceIgnoresHowDeepTheNestingWent(): void
    {
        // What a reset does after a handler died several begin() calls deep: the frame
        // is not unwound one level at a time, it is over. Uncovered until now, which
        // means nothing said whether the depth was cleared or merely decremented — and a
        // decrement would leave the next operation holding somebody else's records.
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->open();
        $buffer->open();
        $buffer->hold(new AuditRecord('stock', 1, 'update', changes: ['fact' => new Change(1, 2)]));

        $released = $buffer->closeAll();

        self::assertNotNull($released);
        self::assertCount(1, $released);
        self::assertFalse($buffer->isOpen(), 'three begins and one closeAll leave nothing open');
        self::assertNull($buffer->closeAll(), 'and there is nothing left to close');
    }

}
