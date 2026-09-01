<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use PHPUnit\Framework\TestCase;

final class ChangeRedactorTest extends TestCase
{
    public function testTheValueGoesAndTheFactThatItChangedStays(): void
    {
        $record = self::record(['password' => new Change('hunter2', 'letmein'), 'name' => new Change('a', 'b')]);

        $redacted = (new ChangeRedactor(['password']))->redact($record);

        self::assertSame(['old' => '***', 'new' => '***'], $redacted->toDocument()['changes']['password']);
        self::assertSame(['old' => 'a', 'new' => 'b'], $redacted->toDocument()['changes']['name'], 'other fields are untouched');
    }

    public function testNothingStaysNothing(): void
    {
        $record = self::record(['password' => new Change(null, 'letmein'), 'note' => new Change('', 'x')]);

        $redacted = (new ChangeRedactor(['password', 'note']))->redact($record)->toDocument()['changes'];

        self::assertSame(['old' => null, 'new' => '***'], $redacted['password'], 'was not set, now is — without saying what');
        self::assertSame(['old' => '', 'new' => '***'], $redacted['note']);
    }

    public function testFieldsCanBeScopedToAnObjectType(): void
    {
        $redactor = new ChangeRedactor(['user.email']);

        $user = $redactor->redact(self::record(['email' => new Change('a@b.c', 'd@e.f')], 'user'));
        $order = self::record(['email' => new Change('a@b.c', 'd@e.f')], 'order');

        self::assertSame(['old' => '***', 'new' => '***'], $user->toDocument()['changes']['email']);
        self::assertSame($order, $redactor->redact($order), 'an order\'s email is not the one that was named — the record is returned as is');
    }

    public function testFreeFormValuesAndPairsGivenAsArraysAreRedactedToo(): void
    {
        $record = self::record(['token' => 'abc123', 'secret' => ['old' => 'x', 'new' => 'y'], 'ips' => []]);

        $changes = (new ChangeRedactor(['token', 'secret', 'ips'], '[redacted]'))->redact($record)->toDocument()['changes'];

        self::assertSame('[redacted]', $changes['token']);
        self::assertSame(['old' => '[redacted]', 'new' => '[redacted]'], $changes['secret']);
        self::assertSame([], $changes['ips'], 'an empty list has nothing to hide');
    }

    public function testARecordWithNothingToRedactIsReturnedUntouched(): void
    {
        $record = self::record(['name' => new Change('a', 'b')]);

        self::assertSame($record, (new ChangeRedactor(['password']))->redact($record));
    }

    public function testFalseAndZeroAreValuesAndAreRedacted(): void
    {
        $changes = (new ChangeRedactor(['flag', 'count']))->redact(self::record(['flag' => new Change(false, true), 'count' => new Change(0, 3)]))->toDocument()['changes'];

        self::assertSame(['old' => '***', 'new' => '***'], $changes['flag'], 'only null, "" and [] mean "nothing"; false and 0 are values in their own right');
        self::assertSame(['old' => '***', 'new' => '***'], $changes['count']);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function record(array $changes, string $objectType = 'user'): AuditRecord
    {
        return new AuditRecord($objectType, 1, AuditEvent::UPDATE, new \DateTimeImmutable('2026-08-27 10:00:00', new \DateTimeZone('UTC')), 'admin', $changes);
    }

    public function testASecretOneLevelDownIsStillASecret(): void
    {
        // What a tracked collection records is named for the path that reached it, and a
        // rule names a field: "password" has to cover "lines.42.password" or element
        // tracking becomes a way around redaction.
        $redactor = new ChangeRedactor(['password'], '***');

        $record = (new AuditRecord('order', 1, 'update'))->withChanges([
            'lines.42.password' => new Change('hunter2', 'hunter3'),
            'lines.42.quantity' => new Change(1, 2),
        ]);

        $changes = $redactor->redact($record)->changes;

        self::assertEquals(new Change('***', '***'), $changes['lines.42.password']);
        self::assertEquals(new Change(1, 2), $changes['lines.42.quantity']);
    }

    public function testARuleNamingACollectionCoversEverythingReachedThroughIt(): void
    {
        // The membership key "lines.42" ends in an element id no rule can name, and a
        // rule saying "lines" clearly meant the whole collection: what the element was
        // ("widget") is hidden, the fact that one came or went stays.
        $redactor = new ChangeRedactor(['lines'], '***');

        $record = (new AuditRecord('shipment', 1, 'update'))->withChanges([
            'lines.42' => new Change(null, 'widget'),
            'lines.42.quantity' => new Change(1, 2),
            'carrier' => new Change('a', 'b'),
        ]);

        $changes = $redactor->redact($record)->changes;

        self::assertEquals(new Change(null, '***'), $changes['lines.42'], 'added, but not what');
        self::assertEquals(new Change('***', '***'), $changes['lines.42.quantity']);
        self::assertEquals(new Change('a', 'b'), $changes['carrier']);
    }

    public function testAPlainRuleIsNotAScopeForAnObjectTypeOfTheSameName(): void
    {
        // The rule "lines" names a field. An object whose *type* happens to be "lines"
        // is a different thing entirely, and "lines.title" — objectType glued to the
        // field for the scoped-rule check — must not read as a path under the rule.
        $redactor = new ChangeRedactor(['lines'], '***');

        $record = (new AuditRecord('lines', 1, 'update'))->withChanges(['title' => new Change('a', 'b')]);

        self::assertSame($record, $redactor->redact($record), 'nothing here is named by the rule');
    }

    public function testAScopedCollectionRuleCoversTheSamePathsOnItsTypeOnly(): void
    {
        $redactor = new ChangeRedactor(['shipment.lines'], '***');

        $shipment = $redactor->redact((new AuditRecord('shipment', 1, 'update'))->withChanges(['lines.42' => new Change('widget', null)]));
        $order = (new AuditRecord('order', 1, 'update'))->withChanges(['lines.42' => new Change('widget', null)]);

        self::assertEquals(new Change('***', null), $shipment->changes['lines.42']);
        self::assertSame($order, $redactor->redact($order), 'an order\'s lines are not the ones that were named');
    }
}
