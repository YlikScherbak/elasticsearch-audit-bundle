<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use PHPUnit\Framework\TestCase;

/**
 * An entry read from a hit whose fields are not the strings the bundle writes -- a number where
 * a name was expected, written by an older version or by hand -- and the extra a decorator adds.
 */
final class WhatAnEntryReadsOfAHitTest extends TestCase
{
    public function testANumberWhereTextIsExpectedIsReadAsItsText(): void
    {
        $entry = AuditEntry::fromHit(['_id' => 'a', '_source' => ['objectType' => 5, 'objectId' => 1, 'event' => 7, 'loggedAt' => '2026-10-03 12:00:00', 'source' => 42]]);

        self::assertSame(['5', '7', '42'], [$entry->objectType, $entry->event, $entry->actor]);
    }

    public function testExtraIsAddedToAndNotReplaced(): void
    {
        $entry = AuditEntry::fromHit(['_id' => 'a', '_source' => ['objectType' => 'order', 'objectId' => 1, 'event' => 'update', 'loggedAt' => '2026-10-03 12:00:00']])
            ->withExtra(['actorName' => 'Alice'])
            ->withExtra(['objectTitle' => 'Order #1']);

        self::assertSame(['actorName' => 'Alice', 'objectTitle' => 'Order #1'], $entry->extra);
    }
}
