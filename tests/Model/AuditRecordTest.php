<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use PHPUnit\Framework\TestCase;

final class AuditRecordTest extends TestCase
{
    public function testDocumentHasTheSharedLayout(): void
    {
        $record = new AuditRecord(
            objectType: 'order',
            objectId: 42,
            event: AuditEvent::UPDATE,
            loggedAt: new \DateTimeImmutable('2026-08-26 15:30:00', new \DateTimeZone('Europe/Kyiv')),
            actor: '7',
            changes: ['status' => new Change('new', 'paid'), 'note' => 'free-form'],
            attributes: ['salesType' => 3],
        );

        self::assertSame([
            'objectType' => 'order',
            'objectId' => 42,
            'event' => 'update',
            'loggedAt' => '2026-08-26 12:30:00',
            'source' => '7',
            'changes' => [
                'status' => ['old' => 'new', 'new' => 'paid'],
                'note' => 'free-form',
            ],
            'salesType' => 3,
        ], $record->toDocument());
    }

    public function testTheIdIsPartOfTheDocumentWhenSet(): void
    {
        $record = (new AuditRecord('order', 1, AuditEvent::CREATE, new \DateTimeImmutable('2026-08-26 12:00:00', new \DateTimeZone('UTC'))))->withId('0198e6b0-1234-7abc-8def-0123456789ab');

        self::assertSame('0198e6b0-1234-7abc-8def-0123456789ab', $record->id);
        self::assertSame('0198e6b0-1234-7abc-8def-0123456789ab', $record->toDocument()['id']);
        self::assertContains('id', AuditRecord::reservedFields());
    }

    public function testTimestampIsAlwaysWrittenInUtc(): void
    {
        $record = (new AuditRecord('user', 'u-1', AuditEvent::CREATE))
            ->withLoggedAt(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('America/New_York')));

        self::assertSame('2026-01-01 05:00:00', $record->toDocument()['loggedAt']);
    }

    public function testWithersReturnCopies(): void
    {
        $original = new AuditRecord('user', 1, AuditEvent::CREATE);
        $changed = $original->withActor('admin')->withChange('name', 'a', 'b')->withAttributes(['tenant' => 'acme']);

        self::assertNull($original->actor);
        self::assertSame([], $original->changes);
        self::assertSame('admin', $changed->actor);
        self::assertTrue($changed->hasChanges());
        self::assertSame(['tenant' => 'acme'], $changed->attributes);
    }

    public function testAttributesCannotShadowBaseFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"source" is a reserved document field');

        (new AuditRecord('user', 1, AuditEvent::CREATE))->withAttributes(['source' => 'x']);
    }

    public function testTheConstructorRefusesReservedAttributesTheSameWay(): void
    {
        // The same mistake through the other door: withAttributes() refused it while
        // the constructor silently dropped it from the document — the caller believed
        // "source" was set and the index never saw it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"source" is a reserved document field');

        new AuditRecord('user', 1, AuditEvent::CREATE, attributes: ['source' => 'x']);
    }

    public function testLaterAttributesOverrideEarlierOnes(): void
    {
        $record = (new AuditRecord('user', 1, AuditEvent::CREATE))
            ->withAttributes(['tenant' => 'a', 'region' => 'eu'])
            ->withAttributes(['tenant' => 'b']);

        self::assertSame(['tenant' => 'b', 'region' => 'eu'], $record->attributes);
    }

    public function testDocumentNeedsATimestamp(): void
    {
        $this->expectException(\LogicException::class);

        (new AuditRecord('user', 1, AuditEvent::CREATE))->toDocument();
    }

    public function testObjectTypeAndEventMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditRecord('', 1, AuditEvent::CREATE);
    }

    public function testLifecycleEventsAreRecognised(): void
    {
        self::assertTrue(AuditEvent::isLifecycle('update'));
        self::assertFalse(AuditEvent::isLifecycle('order_call'));
    }
}
