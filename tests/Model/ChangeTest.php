<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Model\Change;
use PHPUnit\Framework\TestCase;

final class ChangeTest extends TestCase
{
    public function testDatesAreFormattedLikeTheMapping(): void
    {
        $change = new Change(
            new \DateTimeImmutable('2026-08-26 10:00:00', new \DateTimeZone('UTC')),
            null,
        );

        self::assertSame(['old' => '2026-08-26 10:00:00', 'new' => null], $change->toArray());
    }

    public function testNestedDatesInsideCollectionsAreFormattedToo(): void
    {
        $change = new Change([], [['name' => 'x', 'at' => new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))]]);

        self::assertSame(['old' => [], 'new' => [['name' => 'x', 'at' => '2026-01-01 00:00:00']]], $change->toArray());
    }

    public function testPairDetection(): void
    {
        self::assertTrue(Change::isPair(['old' => 1, 'new' => 2]));
        self::assertTrue(Change::isPair(['old' => null, 'new' => null]));
        self::assertFalse(Change::isPair(['old' => 1]));
        self::assertFalse(Change::isPair('scalar'));
    }
}
