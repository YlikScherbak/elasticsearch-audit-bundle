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

    public function testTheChainIsAComparatorLikeAnyOther(): void
    {
        // It is what the frame and the Doctrine listener both receive, and the listener
        // asks for the interface: a chain that does not implement it cannot be built,
        // which is how 0.9.0 shipped a listener that raised a TypeError on first flush.
        self::assertInstanceOf(ValueComparatorInterface::class, new ValueComparator([]));
    }

    public function testTheChainIsTheOnlyDefaultAnswerThereIs(): void
    {
        // ChangeSetBuilder used to keep a strict comparison of its own, and the two had
        // drifted: an array holding two dates for the same instant was a change to one
        // and not to the other, so a collection snapshot said different things depending
        // on whether a comparator had been injected. There is one answer now, this one.
        $utc = new \DateTimeImmutable('2026-08-30 10:00:00', new \DateTimeZone('UTC'));
        $kyiv = new \DateTimeImmutable('2026-08-30 13:00:00', new \DateTimeZone('+03:00'));

        self::assertTrue(ValueComparator::same([$utc], [$kyiv]), 'the same instant, wherever it is written from');
        self::assertFalse([$utc] === [$kyiv], 'which a strict comparison of the two arrays would deny');
    }

    public function testTheTwoWaysAnArrayIsTheSameWithoutBeingIdentical(): void
    {
        // The whole of the difference the merged comparison makes, and nothing else:
        // both used to be recorded as changes by the Doctrine listener, which compared
        // arrays with ===.
        $comparator = new ValueComparator();
        $noon = new \DateTimeImmutable('2026-08-30 12:00:00', new \DateTimeZone('UTC'));
        $elsewhere = new \DateTimeImmutable('2026-08-30 14:00:00', new \DateTimeZone('+02:00'));

        self::assertTrue($comparator->equals('order', 'meta', ['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]), 'a map is its keys, not their order');
        self::assertTrue($comparator->equals('order', 'dates', [$noon], [$elsewhere]), 'the recursion reaches the dates, and dates are instants');
    }

    public function testAndTheThreeThatLookLikeItButAreChanges(): void
    {
        // Easy to "fix" on autopilot into the case above. None of them is that case.
        $comparator = new ValueComparator();

        self::assertFalse($comparator->equals('order', 'ids', [1, 2], [2, 1]), 'a list is ordered: the keys are positions, and they hold different values');
        self::assertFalse($comparator->equals('order', 'ids', ['1'], [1]), 'the recursion ends in ===, so a string that became an int is a change');
        self::assertFalse($comparator->equals('order', 'ids', [1], [1, 2]), 'and a different length is a different value');
    }

    public function testDatesAreComparedToTheMicrosecond(): void
    {
        // Until 0.9.3 this was getTimestamp(), whole seconds, so a change made inside one
        // second was recorded as no change at all.
        $comparator = new ValueComparator();

        $early = new \DateTimeImmutable('2026-08-30 10:00:00.100000', new \DateTimeZone('UTC'));
        $late = new \DateTimeImmutable('2026-08-30 10:00:00.900000', new \DateTimeZone('UTC'));

        self::assertFalse($comparator->equals('order', 'at', $early, $late));
        self::assertTrue($comparator->equals('order', 'at', $early, new \DateTimeImmutable('2026-08-30 12:00:00.100000', new \DateTimeZone('+02:00'))), 'the same instant, written from another zone');
    }

    public function testTheCornersOfDateComparison(): void
    {
        $comparator = new ValueComparator();

        $beforeEpoch = new \DateTimeImmutable('1969-07-20 20:17:40.000000', new \DateTimeZone('UTC'));

        self::assertTrue($comparator->equals('order', 'at', $beforeEpoch, new \DateTimeImmutable('1969-07-20 20:17:40.000000', new \DateTimeZone('UTC'))));
        self::assertFalse($comparator->equals('order', 'at', $beforeEpoch, new \DateTimeImmutable('1969-07-20 20:17:40.000001', new \DateTimeZone('UTC'))));
        self::assertTrue($comparator->equals('order', 'at', $beforeEpoch, new \DateTime('1969-07-20 20:17:40.000000', new \DateTimeZone('UTC'))), 'mutable against immutable is still an instant');
    }
}
