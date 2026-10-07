<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Exception\RedactionLimitExceeded;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use PHPUnit\Framework\TestCase;

/**
 * One pass of the redactor, on its own.
 *
 * The writer redacts every record twice - before RecordCreatedEvent and after it, because a
 * listener may hand back a record of its own - and a value the first pass left unwalked is
 * walked by the second. What the writer writes is right either way; what one pass does is
 * the redactor's contract, and it is what AuditCollector::redacts() and anybody calling the
 * redactor directly rely on. So it is held here, one pass at a time.
 */
final class WhatOnePassOfRedactionDoesTest extends TestCase
{
    public function testADatesPublicPropertyInAnAttributeIsMaskedInOnePass(): void
    {
        $redacted = (new ChangeRedactor(['password']))->redact($this->record(attributes: ['at' => new ADateWithAPublicPassword('2026-10-07 10:00:00', new \DateTimeZone('UTC'))]));

        self::assertEquals('***', $redacted->attributes['at']->password);
        self::assertSame('2026-10-07 10:00:00.000000', $redacted->attributes['at']->date);
    }

    public function testADateThatSerialisesToAValueIsThatValueInAnAttribute(): void
    {
        $redacted = (new ChangeRedactor(['password']))->redact($this->record(attributes: [
            'stamp' => new ADateSerialisingTo('a stamp'),
            'empty' => new ADateSerialisingTo(new \stdClass()),
        ]));

        self::assertSame('a stamp', $redacted->attributes['stamp']);
        self::assertSame('{}', json_encode($redacted->attributes['empty']), 'an empty object read back as an empty array');
    }

    public function testADateInAnAttributeIsReadWithoutAWriteAroundIt(): void
    {
        // Outside once() nothing is remembered, and asking must not need it to be.
        $redacted = ChangeRedactor::materialisingOnly()->redact($this->record(attributes: ['at' => new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('UTC'))]));

        self::assertSame('2026-10-07 10:00:00.000000', $redacted->attributes['at']->date);
    }

    public function testWithinOneWriteADateIsAskedForItsJsonOnce(): void
    {
        $date = new ADateSerialisingTo('counted');
        $redactor = new ChangeRedactor(['password']);
        $record = $this->record(attributes: ['at' => $date]);

        $redactor->once(static function () use ($redactor, $record): void {
            $redactor->redact($record);
            $redactor->redact($record);
        });

        self::assertSame(1, $date->asked);

        // And outside one, asked again: the next write's value is the next write's.
        $redactor->redact($record);
        self::assertSame(2, $date->asked);
    }

    /** @return iterable<string, array{?list<string>, string}> */
    public static function circles(): iterable
    {
        yield 'with a rule' => [['password'], 'redaction cannot see the bottom of it'];
        yield 'with no rule' => [null, 'so it could not be serialised'];
    }

    /**
     * @param list<string>|null $rules
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('circles')]
    public function testAWrapperWhoseAnswerHoldsItselfIsACircle(?array $rules, string $saying): void
    {
        $redactor = $rules === null ? ChangeRedactor::materialisingOnly() : new ChangeRedactor($rules);

        try {
            $redactor->redact($this->record(['loop' => new AnswersWithItself()]));
            self::fail('a value that leads back into itself was walked');
        } catch (RedactionLimitExceeded $e) {
            self::assertStringContainsString('leads back into itself', $e->getMessage());
            self::assertStringContainsString($saying, $e->getMessage());
        }
    }

    public function testOneWrapperInTwoPlacesIsNoCircle(): void
    {
        $shared = new AnswersWithAnArray();

        $redacted = ChangeRedactor::materialisingOnly()->redact($this->record(['a' => $shared, 'b' => [$shared]]));

        self::assertSame(['status' => 'active'], $redacted->changes['a']);
        self::assertSame([['status' => 'active']], $redacted->changes['b']);
    }

    public function testTheDefaultBudgetIsARulesAndNotAMaterialisationsOwn(): void
    {
        // Ten thousand places with a rule, as since 1.0; none without one, so that a record
        // nothing is redacted from is bounded only as JSON bounds it.
        $wide = $this->record(['lines' => range(1, ChangeRedactor::DEFAULT_MAX_NODES + 1)]);

        self::assertCount(ChangeRedactor::DEFAULT_MAX_NODES + 1, ChangeRedactor::materialisingOnly()->redact($wide)->changes['lines']);

        $this->expectException(RedactionLimitExceeded::class);
        $this->expectExceptionMessage((string) ChangeRedactor::DEFAULT_MAX_NODES);
        (new ChangeRedactor(['password']))->redact($wide);
    }

    public function testThePairsOwnSidesAreNotNamesARuleCanReach(): void
    {
        // A rule called "new" would otherwise blank every hand-built pair in the log.
        $redacted = (new ChangeRedactor(['new', 'note']))->redact($this->record(['status' => ['old' => 'draft', 'new' => 'paid', 'note' => 'by hand']]));

        self::assertSame(['old' => 'draft', 'new' => 'paid', 'note' => '***'], $redacted->changes['status']);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $attributes
     */
    private function record(array $changes = [], array $attributes = []): AuditRecord
    {
        return new AuditRecord('user', 1, AuditEvent::UPDATE, changes: $changes, attributes: $attributes);
    }
}

final class ADateWithAPublicPassword extends \DateTimeImmutable
{
    public string $password = 'kept back';
}

final class ADateSerialisingTo extends \DateTimeImmutable implements \JsonSerializable
{
    public int $asked = 0;

    public function __construct(private readonly mixed $answer)
    {
        parent::__construct('2026-10-07 10:00:00', new \DateTimeZone('UTC'));
    }

    public function jsonSerialize(): mixed
    {
        ++$this->asked;

        return $this->answer;
    }
}

final class AnswersWithItself implements \JsonSerializable
{
    public function jsonSerialize(): array
    {
        return ['me' => $this];
    }
}

final class AnswersWithAnArray implements \JsonSerializable
{
    public function jsonSerialize(): array
    {
        return ['status' => 'active'];
    }
}
