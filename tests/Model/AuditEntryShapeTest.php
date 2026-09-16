<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use PHPUnit\Framework\TestCase;

/**
 * The shape an entry has when it leaves for a JSON response, and what it answers for a
 * field nobody wrote.
 *
 * Both are contracts with somebody outside this bundle — a controller serialising a
 * page, a template reading an attribute — so both are worth stating rather than
 * leaving to whatever the array literal happens to say this week.
 */
final class AuditEntryShapeTest extends TestCase
{
    public function testAnAttributeNobodyWroteAnswersWithTheDefault(): void
    {
        // Enrichers come and go, so a page always holds rows from before one started
        // adding its field. Asking for it has to answer the caller's default rather
        // than null-by-accident, because a template distinguishing "no channel" from
        // "channel unknown" can only do it if the entry keeps the difference.
        $entry = $this->entry(['salesChannel' => 'web']);

        self::assertSame('web', $entry->attribute('salesChannel'));
        self::assertNull($entry->attribute('warehouse'));
        self::assertSame('none recorded', $entry->attribute('warehouse', 'none recorded'));
        self::assertSame('none recorded', $entry->attribute('', 'none recorded'));
    }

    public function testTheShapeAnEntryLeavesIn(): void
    {
        // Every key, by name, because this array is a public contract: it is what
        // AuditPage::toArray() puts in a JSON response, and a key quietly renamed or
        // dropped breaks a caller the bundle cannot see. The date is the format the
        // index stores, not the one a locale would choose.
        $entry = $this->entry([]);

        self::assertSame(
            ['id', 'objectType', 'objectId', 'event', 'loggedAt', 'actor', 'changes'],
            array_keys($entry->toArray()),
        );

        self::assertSame([
            'id' => 'entry-1',
            'objectType' => 'order',
            'objectId' => 17,
            'event' => 'update',
            'loggedAt' => '2026-08-30T10:00:00+00:00',
            'actor' => 'alice',
            'changes' => ['status' => ['old' => 'new', 'new' => 'paid']],
        ], $entry->toArray());
    }

    public function testAnEnrichersFieldsSitAlongsideTheGenericOnes(): void
    {
        // Flat rather than nested under a key of their own: what an enricher adds is a
        // fact about the record like any other, and a caller filtering the history by
        // salesChannel reads it the same way it reads objectType. Which also means the
        // generic keys are not optional - an attribute cannot quietly take one over.
        $enriched = $this->entry(['salesChannel' => 'web'])->toArray();

        self::assertSame('web', $enriched['salesChannel'] ?? null);
        self::assertSame('order', $enriched['objectType'], 'an attribute displaced a key the shape promises');

        // And nothing is added for an entry that has none: a key present on every row
        // whether or not it says anything stops being read at all.
        self::assertSame(
            ['id', 'objectType', 'objectId', 'event', 'loggedAt', 'actor', 'changes'],
            array_keys($this->entry([])->toArray()),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function entry(array $attributes): AuditEntry
    {
        return new AuditEntry(
            id: 'entry-1',
            objectType: 'order',
            objectId: 17,
            event: 'update',
            loggedAt: new \DateTimeImmutable('2026-08-30 10:00:00', new \DateTimeZone('UTC')),
            actor: 'alice',
            changes: ['status' => ['old' => 'new', 'new' => 'paid']],
            attributes: $attributes,
        );
    }
}
