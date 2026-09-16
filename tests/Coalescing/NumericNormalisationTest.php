<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One spelling per quantity, and what happens to the spellings nobody can read.
 *
 * The comparator exists because a decimal column answers "12.00" where the form sent
 * "12", and a record saying the quantity changed from 12 to 12 is noise in a history
 * people are meant to read. Which makes its failure mode the expensive one: a **false
 * equal deletes a real change from the audit trail**, and nothing downstream can
 * notice, because there is nothing left to notice.
 *
 * So the normalisation is exact rather than convenient — the exponent is applied by
 * moving the decimal point through the digit string, not by casting to float, because
 * a float turns 0.1 + 0.2 into 0.30000000000000004 and 1e-15 into something
 * indistinguishable from zero. What is pinned below is that exactness, at each of the
 * places the arithmetic could quietly round, and the refusals: a number nobody can
 * read gets **no opinion** rather than a guess, which costs one extra record at worst.
 */
final class NumericNormalisationTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed, bool|null}>
     */
    public static function quantities(): iterable
    {
        // The ordinary reason this class exists.
        yield 'a decimal column and a form field' => ['12', '12.00', true];
        yield 'leading zeroes' => ['00012.00', '12', true];
        yield 'nothing and zero' => [null, '0', true];
        yield 'an empty string and zero' => ['', '0.000', true];
        yield 'a lone minus and zero' => ['-', '0', true];
        yield 'minus zero and zero' => ['-0.00', '0', true];
        yield 'a real change' => ['12', '13', false];
        yield 'a number written without its leading zero' => ['.5', '0.5', true];
        yield 'a sign is not decoration' => ['-5', '5', false];
        yield 'and one negative is not another' => ['-5', '-6', false];
        yield 'a real change in the hundredths' => ['12.00', '12.01', false];

        // Scientific notation, which is what a JSON encoder reaches for on its own.
        yield 'an exponent and the same number written out' => ['1e3', '1000', true];
        yield 'a negative exponent' => ['1e-3', '0.001', true];
        yield 'an exponent that lands exactly on the last digit' => ['1e0', '1', true];
        yield 'a fraction with an exponent' => ['1.5e2', '150', true];
        yield 'a fraction the exponent does not consume' => ['1.25e1', '12.5', true];
        yield 'a capital E' => ['2E2', '200', true];
        yield 'a signed exponent' => ['2e+2', '200', true];
        yield 'a negative number with an exponent' => ['-1.5e2', '-150', true];

        // The two false equals a float would produce, and the whole reason the exponent
        // is applied by hand: both of these are real changes.
        yield 'a very small number is not zero' => ['1e-15', '0', false];
        yield 'precision a float would lose' => ['0.1', '0.10000000000000001', false];
        yield 'an exponent away from a different number' => ['1e3', '1001', false];

        // A quantity nobody could hold. Writing 1e100000000 out is a hundred megabytes
        // of zeroes, so there is no opinion — and no opinion is an extra record at
        // worst, while materialising it is the process.
        yield 'an exponent at the greatest readable size' => ['1e4096', '1e4096', true];
        yield 'an exponent past it' => ['1e4097', '1e4097', null];
        yield 'a hugely negative exponent' => ['1e-4097', '0', null];

        // Not numbers at all. An opinion here would be a guess about text.
        yield 'something with a number in it' => ['x1e5', '100000', null];
        yield 'something after the number' => ['1e5x', '100000', null];
        yield 'an exponent with nothing after it' => ['1e', '0', null];
        yield 'a capital E with nothing after it' => ['1E', '0', null];
        yield 'a word' => ['twelve', '12', null];
        yield 'two numbers' => ['1 2', '12', null];
    }

    /**
     * @param bool|null $expected null means "no opinion" — the chain asks the next
     *                            comparator, and in the end the values are compared as
     *                            they are
     */
    #[DataProvider('quantities')]
    public function testWhichSpellingsAreTheSameQuantity(mixed $old, mixed $new, ?bool $expected): void
    {
        $comparator = new NumericNullAsZeroComparator(['stock.fact']);

        self::assertSame($expected, $comparator->equals('stock', 'fact', $old, $new));
        self::assertSame($expected, $comparator->equals('stock', 'fact', $new, $old), 'the answer changed when the two were swapped');
    }

    public function testAFieldTheRuleIsNotAboutGetsNoOpinionAtAll(): void
    {
        $comparator = new NumericNullAsZeroComparator(['stock.fact']);

        self::assertNull($comparator->equals('order', 'fact', '12', '12.00'), 'another object type');
        self::assertNull($comparator->equals('stock', 'price', '12', '12.00'), 'another field');
    }

    public function testARuleAboutQuantitiesIsAboutQuantitiesWhereverTheySit(): void
    {
        // A field inside a tracked collection element arrives as "lines.quantity", and a
        // rule written for quantities means the ones in the lines too — the same way a
        // redaction rule for "password" covers "lines.42.password".
        $comparator = new NumericNullAsZeroComparator(['quantity']);

        self::assertTrue($comparator->equals('order', 'quantity', '1', '1.0'));
        self::assertTrue($comparator->equals('order', 'lines.quantity', '1', '1.0'));
        self::assertTrue($comparator->equals('order', 'lines.42.quantity', '1', '1.0'));
        self::assertNull($comparator->equals('order', 'lines.price', '1', '1.0'), 'and not about everything in them');
    }
}
