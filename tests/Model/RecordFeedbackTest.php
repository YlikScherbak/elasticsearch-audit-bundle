<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditOrigin;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use PHPUnit\Framework\TestCase;

/**
 * The small things an application needs when it holds a record or an entry: where it
 * came from, how to add without overwriting, and how to hand it back in either shape.
 */
final class RecordFeedbackTest extends TestCase
{
    public function testAttributesCanFillGapsWithoutOverwriting(): void
    {
        $record = (new AuditRecord('order', 1, 'update'))->withAttributes(['channel' => 'web', 'tenant' => 'acme']);

        $filled = $record->withAddedAttributes(['channel' => 'default', 'salesType' => 2]);

        self::assertSame('web', $filled->attributes['channel'], 'what was there stays');
        self::assertSame(2, $filled->attributes['salesType']);
        self::assertSame('acme', $filled->attributes['tenant']);
    }

    public function testAddedAttributesAreStillRefusedWhenReserved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"objectType" is a reserved document field');

        (new AuditRecord('order', 1, 'update'))->withAddedAttributes(['objectType' => 'other']);
    }

    public function testARecordIsTheApplicationsUnlessTheBundleSaysOtherwise(): void
    {
        self::assertSame(AuditOrigin::Manual, (new AuditRecord('order', 1, 'update'))->origin);
        self::assertSame(AuditOrigin::Doctrine, (new AuditRecord('order', 1, 'update', origin: AuditOrigin::Doctrine))->origin);
    }

    public function testOriginSurvivesEveryWither(): void
    {
        // An enricher that adds an attribute must not turn a listener's record into the
        // application's own; that is the whole point of having the flag.
        $record = (new AuditRecord('order', 1, 'update', origin: AuditOrigin::Doctrine))
            ->withLoggedAt(new \DateTimeImmutable('2026-08-30 10:00:00'))
            ->withActor('alice')
            ->withId('01J000000000000000000000')
            ->withChanges(['status' => 'sent'])
            ->withAttributes(['channel' => 'web']);

        self::assertSame(AuditOrigin::Doctrine, $record->origin);
        self::assertArrayNotHasKey('origin', $record->toDocument(), 'it is a fact about the write, not about the history');
    }

    public function testAnEntryCanBeHandedBackInTheShapeItWasStored(): void
    {
        $entry = self::entry();

        self::assertSame([
            'id' => 'entry-1',
            'objectType' => 'order',
            'objectId' => 42,
            'event' => 'update',
            'loggedAt' => '2026-08-30 08:00:00',
            'source' => 'alice',
            'changes' => ['status' => ['old' => 'new', 'new' => 'sent']],
            'salesType' => 2,
        ], $entry->toDocument());
    }

    public function testTheJsonShapeIsTheOtherOne(): void
    {
        $array = self::entry()->toArray();

        self::assertSame('alice', $array['actor']);
        self::assertSame('2026-08-30T08:00:00+00:00', $array['loggedAt']);
        self::assertArrayNotHasKey('source', $array);
    }

    public function testADecoratorsExtraOutranksAStoredAttributeInTheJson(): void
    {
        // A decorator replacing an attribute on the way out — a country code with its
        // name — is read-side enrichment, and toArray() is the read-side shape: extra
        // wins over the stored value. It silently lost before, and the decorator that
        // "worked" changed nothing on the screen. Base fields stay unoverridable.
        $entry = self::entry()->withExtra(['salesType' => 'Retail', 'event' => 'hijacked']);

        $array = $entry->toArray();

        self::assertSame('Retail', $array['salesType'], 'the readable form is what the endpoint answers');
        self::assertSame('update', $array['event'], 'a base field cannot be overridden from extra');
        self::assertSame(2, $entry->toDocument()['salesType'], 'toDocument() is the stored shape and never sees extra');
    }

    public function testADecoratorCanRewriteTheChangesThemselves(): void
    {
        $entry = self::entry();

        $readable = $entry->withChanges(['status' => ['old' => 'Draft', 'new' => 'Sent']]);

        self::assertSame(['status' => ['old' => 'Draft', 'new' => 'Sent']], $readable->changes);
        self::assertSame($entry->id, $readable->id);
        self::assertSame($entry->attributes, $readable->attributes, 'nothing else moves');
        self::assertSame(['status' => ['old' => 'new', 'new' => 'sent']], $entry->changes, 'the entry it came from is untouched');
    }

    private static function entry(): AuditEntry
    {
        return new AuditEntry(
            id: 'entry-1',
            objectType: 'order',
            objectId: 42,
            event: 'update',
            loggedAt: new \DateTimeImmutable('2026-08-30 08:00:00', new \DateTimeZone('UTC')),
            actor: 'alice',
            changes: ['status' => ['old' => 'new', 'new' => 'sent']],
            attributes: ['salesType' => 2],
        );
    }
}
