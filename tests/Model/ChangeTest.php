<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Model\Change;
use PHPUnit\Framework\TestCase;

enum ChangeTestStatus: string
{
    case Draft = 'draft';
}

enum ChangeTestLevel
{
    case High;
}

final class ChangeTest extends TestCase
{
    public function testDatesAreFormattedLikeTheMappingInUtc(): void
    {
        $change = new Change(
            new \DateTime('2026-08-26 13:00:00', new \DateTimeZone('Europe/Kyiv')),
            null,
        );

        self::assertSame(['old' => '2026-08-26 10:00:00', 'new' => null], $change->toArray());
    }

    public function testNestedDatesInsideCollectionsAreFormattedToo(): void
    {
        $change = new Change([], [['name' => 'x', 'at' => new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))]]);

        self::assertSame(['old' => [], 'new' => [['name' => 'x', 'at' => '2026-01-01 00:00:00']]], $change->toArray());
    }

    public function testEnumsAreStoredByValueOrName(): void
    {
        $change = new Change(ChangeTestStatus::Draft, ChangeTestLevel::High);

        self::assertSame(['old' => 'draft', 'new' => 'High'], $change->toArray(), 'a backed enum by its value, a pure enum by its name — json_encode would refuse the latter and the record would be lost');
    }

    public function testPairDetection(): void
    {
        self::assertTrue(Change::isPair(['old' => 1, 'new' => 2]));
        self::assertTrue(Change::isPair(['old' => null, 'new' => null]));
        self::assertFalse(Change::isPair(['old' => 1]));
        self::assertFalse(Change::isPair('scalar'));
    }

    public function testADateKeepsTheFractionTheComparatorJudgedItBy(): void
    {
        // The two halves have to agree about what a value is. ValueComparator reads a
        // date to the microsecond when deciding whether it moved, so seconds-only
        // storage wrote records whose sides were the same string: "it changed from
        // 10:00:00 to 10:00:00", which reads as a bug in the trail rather than as the
        // sub-second change it was.
        $pair = (new Change(
            new \DateTimeImmutable('2026-09-06 10:00:00.100000', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-09-06 10:00:00.900000', new \DateTimeZone('UTC')),
        ))->toArray();

        self::assertSame('2026-09-06 10:00:00.100000', $pair['old']);
        self::assertSame('2026-09-06 10:00:00.900000', $pair['new']);
        self::assertNotSame($pair['old'], $pair['new'], 'a record must not say a value changed into itself');
    }

    public function testADateWithoutAFractionIsWrittenExactlyAsItAlwaysWas(): void
    {
        $pair = (new Change(
            new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-09-07 11:30:00', new \DateTimeZone('UTC')),
        ))->toArray();

        self::assertSame('2026-09-06 10:00:00', $pair['old']);
        self::assertSame('2026-09-07 11:30:00', $pair['new']);
    }
}
