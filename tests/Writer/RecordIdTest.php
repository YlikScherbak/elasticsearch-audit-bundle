<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Writer\RecordId;
use PHPUnit\Framework\TestCase;

final class RecordIdTest extends TestCase
{
    public function testItIsAVersion7Uuid(): void
    {
        $id = RecordId::v7(new \DateTimeImmutable('2026-08-26 12:00:00.123', new \DateTimeZone('UTC')));

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
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
}
