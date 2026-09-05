<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumericNullAsZeroComparatorTest extends TestCase
{
    /**
     * @param list<string> $fields
     */
    #[DataProvider('comparisons')]
    public function testWhatCountsAsTheSameQuantity(array $fields, string $objectType, string $field, mixed $old, mixed $new, ?bool $expected): void
    {
        self::assertSame($expected, (new NumericNullAsZeroComparator($fields))->equals($objectType, $field, $old, $new));
    }

    /**
     * @return iterable<string, array{list<string>, string, string, mixed, mixed, ?bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'null is zero' => [['fact'], 'stock', 'fact', null, 0, true];
        yield 'empty string is zero' => [['fact'], 'stock', 'fact', '', 0, true];
        yield 'dash is zero' => [['fact'], 'stock', 'fact', '-', '0.0', true];
        yield 'the same number written differently' => [['fact'], 'stock', 'fact', '1.0', 1, true];
        yield 'different numbers' => [['fact'], 'stock', 'fact', 1, 2, false];
        yield 'another field is none of its business' => [['fact'], 'stock', 'name', 'a', 'b', null];

        // Values that are neither a number nor "nothing" are not this comparator's call:
        // treating them as zero would make two different values look equal and lose a change.
        yield 'two different words' => [['fact'], 'stock', 'fact', 'abc', 'def', null];
        yield 'a word against a number' => [['fact'], 'stock', 'fact', 'abc', 5, null];
        yield 'booleans' => [['fact'], 'stock', 'fact', true, false, null];
        yield 'an array' => [['fact'], 'stock', 'fact', [1], [2], null];

        // A field can be scoped to one object type.
        yield 'scoped, matching type' => [['stock.fact'], 'stock', 'fact', null, 0, true];
        yield 'scoped, other type' => [['stock.fact'], 'order', 'fact', null, 0, null];
        yield 'unscoped applies to every type' => [['fact'], 'order', 'fact', null, 0, true];
    }

    public function testANonNumericValueFallsBackToTheStrictComparison(): void
    {
        $comparator = new ValueComparator([new NumericNullAsZeroComparator(['fact'])]);

        self::assertFalse($comparator->equals('stock', 'fact', 'abc', 'def'), 'a real change must survive');
        self::assertTrue($comparator->equals('stock', 'fact', 'abc', 'abc'));
        self::assertTrue($comparator->equals('stock', 'fact', null, 0), 'the comparator still has the last word on numbers');
    }

    public function testTwoBigIntegersThatDifferByOneStillDiffer(): void
    {
        // Through a float these two are one double, and a real change disappears. A
        // comparator that says "equal" wrongly deletes history; one that says
        // "different" wrongly only adds a record.
        $comparator = new NumericNullAsZeroComparator(['quantity']);

        self::assertFalse($comparator->equals('stock', 'quantity', '9007199254740992', '9007199254740993'));
        self::assertFalse($comparator->equals('stock', 'quantity', 9007199254740992, 9007199254740993));
    }

    public function testTheSameQuantitySpelledDifferently(): void
    {
        $comparator = new NumericNullAsZeroComparator(['quantity']);

        self::assertTrue($comparator->equals('stock', 'quantity', '00012.00', '12.000'));
        self::assertTrue($comparator->equals('stock', 'quantity', '-0', '0'));
        self::assertTrue($comparator->equals('stock', 'quantity', '12', 12));
        self::assertTrue($comparator->equals('stock', 'quantity', '.5', '0.50'));
        self::assertFalse($comparator->equals('stock', 'quantity', '12.01', '12.1'));
    }

    public function testScientificNotationKeepsItsDigits(): void
    {
        // The exponent form went through a float, so the very precision the plain form
        // is guarded against was lost the moment somebody wrote it with an "e" — and a
        // false "equal" deletes a real change from the trail.
        $comparator = new NumericNullAsZeroComparator(['quantity']);

        self::assertFalse($comparator->equals('stock', 'quantity', '9007199254740993e0', '9007199254740992'));
        self::assertFalse($comparator->equals('stock', 'quantity', '0', '1e-15'), 'a millionth of a billionth is still not nothing');
        self::assertTrue($comparator->equals('stock', 'quantity', '1.2e3', '1200'), 'and the same quantity is still the same quantity');
        self::assertTrue($comparator->equals('stock', 'quantity', '12E-2', '0.12'));
        self::assertTrue($comparator->equals('stock', 'quantity', '-1.5e1', '-15.00'));
    }

    public function testAFloatIsComparedByWhatItActuallyHolds(): void
    {
        $comparator = new NumericNullAsZeroComparator(['quantity']);

        self::assertFalse($comparator->equals('stock', 'quantity', 0.0, 1.0e-15), 'the same trap, arriving as floats');
        self::assertTrue($comparator->equals('stock', 'quantity', 0.5, '0.50'));
    }

    public function testANumberTooLargeForAFloatIsStillReadAsWritten(): void
    {
        // Through a float both were INF, and 'INF' === 'INF' deleted a real change from
        // the trail; the comparator deferred to escape that. Read as text there is
        // nothing to escape — these are simply two different numbers, and it can say so.
        $comparator = new NumericNullAsZeroComparator(['quantity']);

        self::assertFalse($comparator->equals('stock', 'quantity', '1e400', '9e999'));
        self::assertTrue($comparator->equals('stock', 'quantity', '1e400', '10e399'), 'and the same enormous quantity is the same');
    }

    public function testAValueThatIsNotAQuantityAtAllGetsNoOpinion(): void
    {
        // A float can still arrive as INF or NAN, and neither has a quantity to compare.
        $comparator = new NumericNullAsZeroComparator(['quantity']);

        self::assertNull($comparator->equals('stock', 'quantity', INF, INF));
        self::assertNull($comparator->equals('stock', 'quantity', NAN, 1.0));
    }
}
