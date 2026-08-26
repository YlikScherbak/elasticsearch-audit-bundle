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
}
