<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use PHPUnit\Framework\TestCase;

/**
 * The frame buffer at its edges: the default valve, what a reset leaves and says, what a refusal
 * counts and what it leaves for the next operation, and which records are one object.
 */
final class WhatTheBufferPromisesAtItsEdgesTest extends TestCase
{
    public function testTheDefaultValveHoldsTenThousandObjects(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();

        for ($i = 0; $i < 10_000; ++$i) {
            self::assertSame([], $buffer->hold(self::update('order', $i)), 'held, not released');
        }

        self::assertCount(10_000, $buffer->hold(self::update('order', 10_000)), 'the ten thousand and first lets the rest go');
    }

    public function testAResetSaysWhetherAnythingWasThere(): void
    {
        $open = new FrameBuffer();
        $open->open();
        self::assertTrue($open->reset(), 'a frame open with nothing in it');

        $held = new FrameBuffer();
        $held->hold(self::update('order', 1));
        self::assertTrue($held->reset(), 'something held with no frame open');

        $staged = new FrameBuffer(throwOnOverflow: true);
        $staged->open();
        $staged->stage(self::update('note', 1));
        $staged->closeAll();
        $staged->stage(self::update('note', 2));
        self::assertTrue($staged->reset(), 'something staged with no frame open');

        self::assertFalse((new FrameBuffer())->reset(), 'nothing at all');
    }

    public function testAfterAResetTheNextFrameIsAnOrdinaryOne(): void
    {
        // Open, at depth one, and not atomic: neither the depth nor the request of the frame that
        // was reset is the next operation's.
        $buffer = new FrameBuffer();
        $buffer->open(atomic: true);
        $buffer->reset();

        $buffer->open();
        self::assertTrue($buffer->isOpen());
        self::assertFalse($buffer->stagesEverything(), 'not atomic because the last one asked to be');
        self::assertSame([], $buffer->hold(self::update('order', 1)), 'and it holds');
    }

    public function testAnOrdinaryFrameStagesNothing(): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();

        self::assertFalse($buffer->stagesEverything());
    }

    public function testARefusalCountsWhatWasHeldAndWhatWasStaged(): void
    {
        $buffer = new FrameBuffer(throwOnOverflow: true);
        $buffer->open();
        $buffer->hold(self::update('order', 1));
        $buffer->hold(self::update('order', 2));
        $buffer->stage(self::update('note', 1));

        self::assertSame(3, $buffer->refuse());
    }

    public function testAFrameLeftOpenOnARefusedOperationDoesNotRefuseTheNext(): void
    {
        $buffer = new FrameBuffer(maxHeld: 1, throwOnOverflow: true);
        $buffer->open();
        $buffer->hold(self::update('order', 1));

        try {
            $buffer->hold(self::update('order', 2));
            self::fail('the premise: the operation is refused');
        } catch (FrameOverflowException) {
        }

        self::assertSame([], $buffer->closeAll(), 'what a refused operation leaves is nothing');

        $buffer->open();
        $buffer->hold(self::update('order', 3));

        self::assertCount(1, $buffer->close() ?? [], 'the next operation is written');
    }

    /** @return iterable<string, array{AuditRecord, AuditRecord}> */
    public static function twoObjects(): iterable
    {
        yield 'a bar in the type and a backslash where it was' => [self::update('a|b', 'c'), self::update('a\\b', 'c')];
        yield 'a bar in the type and two backslashes where it was' => [self::update('a|b', 'c'), self::update('a\\\\b', 'c')];
        yield 'a bar in the type and none at all' => [self::update('a|b', 'c'), self::update('ab', 'c')];
        yield 'the type and the id cut in other places' => [self::update('ab', 'c'), self::update('a', 'bc')];
        yield 'a bar in the id' => [self::update('a', 'b|c'), self::update('a|b', 'c')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('twoObjects')]
    public function testTwoObjectsAreNeverHeldAsOne(AuditRecord $one, AuditRecord $other): void
    {
        $buffer = new FrameBuffer();
        $buffer->open();
        $buffer->hold($one);
        $buffer->hold($other);

        self::assertCount(2, $buffer->close() ?? [], 'two records, not one merged');
    }

    public function testEveryComparatorFailureOfAClosingFrameIsKeptForTheWriter(): void
    {
        $buffer = new FrameBuffer(new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('the comparator failed for '.$objectType);
            }
        });
        $buffer->open();
        $buffer->hold(self::update('order', 1));
        $buffer->hold(self::update('order', 2));
        $buffer->close();

        self::assertCount(2, $buffer->takeFinalizeFailures());
    }

    private static function update(string $type, int|string $id): AuditRecord
    {
        return new AuditRecord($type, $id, 'update', changes: ['status' => new Change('a', 'b')]);
    }
}
