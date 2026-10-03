<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Exception\RedactionLimitExceeded;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use PHPUnit\Framework\TestCase;

/**
 * What a record costs the redaction's budget, to the place: every key read is one -- the record's
 * own change fields and attributes, the two sides of a change, the keys of a pair or of a
 * structure, a hop from a wrapper to what it serialises to. A record that costs exactly the
 * budget is walked; one place more is refused.
 *
 * The other tests of the limit stay well clear of it, and a rule about where the edge is was free
 * to move by one either way.
 */
final class WhatTheRedactionBudgetCountsTest extends TestCase
{
    /** @return iterable<string, array{AuditRecord, int}> */
    public static function records(): iterable
    {
        yield 'a change of two scalars: its field and its two sides' => [new AuditRecord('o', 1, 'update', changes: ['name' => new Change('a', 'b')]), 3];
        yield 'two of them' => [new AuditRecord('o', 1, 'update', changes: ['name' => new Change('a', 'b'), 'other' => new Change(1, 2)]), 6];
        yield 'attributes alone, one each' => [(new AuditRecord('o', 1, 'update'))->withAttributes(['a' => 1, 'b' => 2, 'c' => 3]), 3];
        yield 'a change as its pair: its field and the pair\'s two keys' => [new AuditRecord('o', 1, 'update', changes: ['name' => ['old' => 'a', 'new' => 'b']]), 3];
        yield 'a change holding a structure: and each key of it' => [new AuditRecord('o', 1, 'update', changes: ['profile' => new Change(['x' => 1, 'y' => 2], null)]), 5];
        yield 'a wrapper serialising to another: and the hop' => [new AuditRecord('o', 1, 'update', changes: ['w' => new Change(null, new WrapsAWrapper())]), 5];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('records')]
    public function testARecordThatCostsTheBudgetIsWalkedAndOneMorePlaceIsNot(AuditRecord $record, int $costs): void
    {
        (new ChangeRedactor(['password'], maxNodes: $costs))->redact($record);

        $this->expectException(RedactionLimitExceeded::class);
        (new ChangeRedactor(['password'], maxNodes: $costs - 1))->redact($record);
    }

    public function testTheSmallestLimitsThereAreAreAccepted(): void
    {
        $record = new AuditRecord('o', 1, 'update', changes: ['name' => 'a']);

        self::assertSame('a', (new ChangeRedactor(['password'], maxDepth: 1, maxNodes: 1))->redact($record)->changes['name']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function noRoom(): iterable
    {
        yield 'no depth' => [0, 10];
        yield 'no places' => [10, 0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noRoom')]
    public function testEitherLimitAtNothingIsRefused(int $depth, int $nodes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChangeRedactor(['password'], maxDepth: $depth, maxNodes: $nodes);
    }

    public function testARuleIsAWholeNameAndNotTheStartOfOne(): void
    {
        // "pass" is a field of its own: "password" is not under it, and "pass.word" is.
        $redacted = (new ChangeRedactor(['pass']))->redact(new AuditRecord('o', 1, 'update', changes: ['password' => 'kept', 'pass.word' => 'gone']));

        self::assertSame(['password' => 'kept', 'pass.word' => '***'], $redacted->changes);
    }

    /** @return iterable<string, array{object}> */
    public static function valuesThatAreNoStructure(): iterable
    {
        yield 'a date' => [new \DateTimeImmutable('2026-10-03 12:00:00')];
        yield 'an enum' => [FixtureSuit::Hearts];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('valuesThatAreNoStructure')]
    public function testADateOrAnEnumIsAValueAndNotAPlaceToLook(object $value): void
    {
        // Each would read as an object with properties, and a rule naming one of them --
        // "timezone", "name" -- would turn the value into a masked array.
        $record = new AuditRecord('o', 1, 'update', changes: ['when' => new Change(null, $value)]);

        $redacted = (new ChangeRedactor(['timezone', 'name', 'value', 'date']))->redact($record);

        self::assertSame($record, $redacted, 'nothing in it to redact');
    }
}

