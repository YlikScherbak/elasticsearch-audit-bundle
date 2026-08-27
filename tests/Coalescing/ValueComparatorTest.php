<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use PHPUnit\Framework\TestCase;

final class ValueComparatorTest extends TestCase
{
    public function testDatesAreComparedByInstant(): void
    {
        $comparator = new ValueComparator();

        self::assertTrue($comparator->equals('order', 'callbackAt',
            new \DateTime('2026-08-27 10:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-08-27 13:00:00', new \DateTimeZone('Europe/Kyiv')),
        ), 'the same moment in two timezones and two classes');

        self::assertFalse($comparator->equals('order', 'callbackAt',
            new \DateTimeImmutable('2026-08-27 10:00:00'),
            new \DateTimeImmutable('2026-08-27 10:00:01'),
        ));
    }

    public function testArraysAreComparedElementByElementAndStrictly(): void
    {
        $comparator = new ValueComparator();

        self::assertTrue($comparator->equals('order', 'tags', ['php', 'es'], ['php', 'es']));
        self::assertTrue($comparator->equals('order', 'p', [['n' => 'a', 'q' => 2]], [['n' => 'a', 'q' => 2]]), 'nested');
        self::assertFalse($comparator->equals('order', 'tags', ['php'], ['php', 'es']));
        self::assertFalse($comparator->equals('order', 'q', ['1'], [1]), 'a string becoming an int is a change, not noise');
        self::assertFalse($comparator->equals('order', 'q', ['a' => 1], ['b' => 1]), 'same values under other keys');
    }

    public function testScalarsAreComparedStrictly(): void
    {
        $comparator = new ValueComparator();

        self::assertTrue($comparator->equals('order', 'total', 10, 10));
        self::assertFalse($comparator->equals('order', 'total', 0, null));
        self::assertFalse($comparator->equals('order', 'total', '10', 10));
        self::assertFalse($comparator->equals('order', 'paid', false, 0));
    }

    public function testTheFirstComparatorWithAnOpinionWins(): void
    {
        $comparator = new ValueComparator([
            self::answering(null),
            self::answering(true),
            self::answering(false),
        ]);

        self::assertTrue($comparator->equals('order', 'total', 1, 2), 'the second one answered');
    }

    public function testWithoutAnOpinionItFallsBackToTheStrictComparison(): void
    {
        $comparator = new ValueComparator([self::answering(null)]);

        self::assertFalse($comparator->equals('order', 'total', 1, 2));
    }

    private static function answering(?bool $opinion): ValueComparatorInterface
    {
        return new class($opinion) implements ValueComparatorInterface {
            public function __construct(private readonly ?bool $opinion)
            {
            }

            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                return $this->opinion;
            }
        };
    }
}
