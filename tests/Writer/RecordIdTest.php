<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Writer\IdSequence;
use Borsche\ElasticsearchAuditBundle\Writer\RecordId;
use PHPUnit\Framework\TestCase;

final class RecordIdTest extends TestCase
{
    public function testItIsAVersion7Uuid(): void
    {
        // Many, not one: the variant's two bits sit beside random ones, and a mask that lets
        // one of those in is wrong for every other id -- one id caught it half the time.
        for ($i = 0; $i < 64; ++$i) {
            $id = RecordId::v7(new \DateTimeImmutable('2026-08-26 12:00:00.123', new \DateTimeZone('UTC')));

            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }
    }

    public function testTheFirst48BitsAreTheMillisecondsOfTheTimestamp(): void
    {
        $at = new \DateTimeImmutable('2026-08-26 12:00:00.123', new \DateTimeZone('UTC'));

        self::assertSame((int) $at->format('Uv'), hexdec(substr(str_replace('-', '', RecordId::v7($at)), 0, 12)));
    }

    public function testIdsSortInTimeOrderAndDifferWithinOneMillisecond(): void
    {
        $at = new \DateTimeImmutable('2026-08-26 12:00:00.000', new \DateTimeZone('UTC'));
        $earlier = RecordId::v7($at);
        $later = RecordId::v7($at->modify('+1 msec'));
        $sameMs = RecordId::v7($at);

        self::assertLessThan(0, strcmp($earlier, $later));
        self::assertNotSame($earlier, $sameMs);
    }

    public function testATimestampBeforeTheEpochStillGivesAWellFormedId(): void
    {
        // UUID v7 cannot say "before 1970" — its timestamp field is unsigned — and a
        // record can (imported history, a corrupt source date read leniently). dechex()
        // of the negative count used to bleed a 16-digit two's complement into the id.
        $id = RecordId::v7(new \DateTimeImmutable('1969-12-31 23:59:59.999', new \DateTimeZone('UTC')));

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        self::assertStringStartsWith('00000000-0000', $id, 'pinned to the epoch, where the order of prehistory does not matter');
    }

    public function testTheIdsOfOneSequenceSortInTheOrderItGaveThem(): void
    {
        $at = new \DateTimeImmutable('2026-08-26 12:00:00.123', new \DateTimeZone('UTC'));
        $sequence = new IdSequence(RecordId::millisecondOf($at));
        $ids = [];

        for ($i = 0; $i < 1000; ++$i) {
            $ids[] = RecordId::v7($at, $sequence);
        }

        $sorted = $ids;
        sort($sorted, \SORT_STRING);

        self::assertSame($ids, $sorted, 'as a string, the way Elasticsearch sorts a keyword');
        self::assertCount(1000, array_unique($ids));

        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
            self::assertSame((int) $at->format('Uv'), hexdec(substr(str_replace('-', '', $id), 0, 12)));
        }
    }

    public function testTheCounterCarriesFromItsLowerBitsIntoRandAWithoutTouchingVersionOrVariant(): void
    {
        // Across 2^30: the lower 30 bits roll over and the 12 in rand_a go up by one -- the
        // packing a short run never reaches.
        $at = new \DateTimeImmutable('@0');
        $sequence = new IdSequence(0, 2 ** 30 - 2);
        $ids = [RecordId::v7($at, $sequence), RecordId::v7($at, $sequence), RecordId::v7($at, $sequence), RecordId::v7($at, $sequence)];

        $sorted = $ids;
        sort($sorted, \SORT_STRING);
        self::assertSame($ids, $sorted);

        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }

        self::assertSame(['7000', '7000', '7001', '7001'], array_map(static fn (string $id): string => substr($id, 14, 4), $ids), 'rand_a: 0, then 1 once the lower bits roll over');
        self::assertSame(['bfff-fffe', 'bfff-ffff', '8000-0000', '8000-0001'], array_map(static fn (string $id): string => substr($id, 19, 4).'-'.substr($id, 24, 4), $ids), 'the variant, then the lower 30 bits');
    }

    public function testASequenceThatRunsOutIsAnErrorAndNotANewStart(): void
    {
        $at = new \DateTimeImmutable('@0');
        $sequence = new IdSequence(0, 2 ** 42 - 1);
        $last = RecordId::v7($at, $sequence);

        self::assertSame('00000000-0000-7fff-bfff-', substr($last, 0, 24), 'the last value: every counter bit set, version and variant intact');

        $this->expectException(\OverflowException::class);
        RecordId::v7($at, $sequence);
    }

    public function testASequenceOfAnotherMillisecondIsNotUsed(): void
    {
        // A record dated elsewhere than its moment -- the caller set loggedAt -- gets random bits,
        // and the moment's counter is not spent on it.
        $sequence = new IdSequence(5, 100);
        RecordId::v7(new \DateTimeImmutable('@0'), $sequence);

        self::assertSame(100, $sequence->take());
    }

    public function testASequenceBeginsInTheLowerHalfOfItsBits(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            self::assertLessThan(2 ** 41, (new IdSequence(0))->take());
        }
    }

    public function testTheRandomBitsAreIndependent(): void
    {
        // The two variant bits and the first rand_b nibble come from different bytes: if they
        // shared bits, the low two bits of both would always agree.
        $agreements = 0;

        for ($i = 0; $i < 400; ++$i) {
            $hex = str_replace('-', '', RecordId::v7(new \DateTimeImmutable('@0')));
            $agreements += (hexdec($hex[16]) & 0x3) === (hexdec($hex[17]) & 0x3) ? 1 : 0;
        }

        self::assertLessThan(200, $agreements, 'about one in four should agree by chance, never all of them');
    }

    public function testNoRandomBitOfAnIdIsAnotherOneAgain(): void
    {
        // The 74 bits after the timestamp and the version that are not the variant's top two:
        // rand_a, the variant's low two, rand_b. Each has to be a bit of its own -- one read twice
        // from the same byte, or from a place another part reads, is a bit the id does not have.
        // Over 512 ids two independent bits agree in every one of them with a chance of 2^-512.
        $bits = [];

        for ($i = 0; $i < 512; ++$i) {
            $hex = str_replace('-', '', RecordId::v7(new \DateTimeImmutable('@0')));
            $binary = '';

            foreach (str_split($hex) as $nibble) {
                $binary .= str_pad(decbin((int) hexdec($nibble)), 4, '0', \STR_PAD_LEFT);
            }

            // 48 of time, 4 of version, 12 rand_a, 2 of variant fixed, 2 of it random, 60 rand_b.
            $bits[] = substr($binary, 52, 12).substr($binary, 66, 62);
        }

        $width = \strlen($bits[0]);
        self::assertSame(74, $width);

        for ($a = 0; $a < $width; ++$a) {
            $column = implode('', array_map(static fn (string $id): string => $id[$a], $bits));
            self::assertStringContainsString('0', $column, sprintf('bit %d is always 1', $a));
            self::assertStringContainsString('1', $column, sprintf('bit %d is always 0', $a));

            for ($b = $a + 1; $b < $width; ++$b) {
                $other = implode('', array_map(static fn (string $id): string => $id[$b], $bits));

                if ($column === $other) {
                    self::fail(sprintf('bits %d and %d are the same bit', $a, $b));
                }
            }
        }
    }

    public function testEveryVariantNibbleOccurs(): void
    {
        // 8, 9, a and b: the variant is 10 in its top two bits and random in its low two.
        $seen = [];

        for ($i = 0; $i < 256; ++$i) {
            $seen[RecordId::v7(new \DateTimeImmutable('@0'))[19]] = true;
        }

        ksort($seen);
        self::assertSame(['8', '9', 'a', 'b'], array_map('strval', array_keys($seen)));
    }

    public function testATimestampPastWhatFortyEightBitsHoldIsTheLastMillisecondTheyDo(): void
    {
        // 2^48 milliseconds are the year 10889; a record dated later -- a corrupt source read
        // leniently -- is pinned to the last of them, in twelve digits like any other.
        self::assertStringStartsWith('ffffffff-ffff-7', RecordId::v7(new \DateTimeImmutable('@300000000000')));
        self::assertSame(2 ** 48 - 1, RecordId::millisecondOf(new \DateTimeImmutable('@300000000000')));

        // And one short of it is its own millisecond, not pinned.
        $late = new \DateTimeImmutable('@200000000000');
        self::assertSame(200000000000000, RecordId::millisecondOf($late));
        self::assertSame(200000000000000, hexdec(substr(str_replace('-', '', RecordId::v7($late)), 0, 12)));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function starts(): iterable
    {
        yield 'zero' => [0, true];
        yield 'the last there is' => [2 ** IdSequence::BITS - 1, true];
        yield 'one past it' => [2 ** IdSequence::BITS, false];
        yield 'below zero' => [-1, false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('starts')]
    public function testASequenceBeginsWithinItsBits(int $start, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }

        self::assertSame($start, (new IdSequence(0, $start))->take());
    }

    public function testARandomBeginningUsesTheWholeLowerHalf(): void
    {
        // Not a fixed point, and not a narrower range: of 64 sequences, two begin apart and one
        // begins in the upper half of the lower half, each with a chance of failing of 2^-41 and
        // 2^-64.
        $starts = [];

        for ($i = 0; $i < 64; ++$i) {
            $starts[] = (new IdSequence(0))->take();
        }

        self::assertGreaterThan(1, \count(array_unique($starts)));
        self::assertGreaterThanOrEqual(2 ** 40, max($starts));
    }
}
