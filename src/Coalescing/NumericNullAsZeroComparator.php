<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;

/**
 * For quantity-like fields, "no value" (null, '', '-') and 0 mean the same thing,
 * so a stock line going from null to 0 is not a change. Configured with
 * coalescing.numeric_fields, as "quantity" for every object type or "stock.quantity"
 * for one; a rule also reaches that field inside a tracked collection element
 * ("lines.quantity"). Fields not listed are left to the default comparison.
 *
 * A value that is neither a number nor "no value" is nobody's quantity, so the
 * comparator defers instead of calling it zero — otherwise two different words
 * would look equal and a real change would disappear.
 *
 * @internal registered from coalescing.numeric_fields
 */
final class NumericNullAsZeroComparator implements ValueComparatorInterface
{
    /**
     * @param list<string> $fields
     */
    public function __construct(private readonly array $fields)
    {
    }

    public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
    {
        if (!$this->covers($objectType, $field)) {
            return null;
        }

        $before = self::canonical($old);
        $after = self::canonical($new);

        return $before === null || $after === null ? null : $before === $after;
    }

    /**
     * Named plainly or scoped — and, for a field inside a tracked collection element
     * ("lines.quantity"), by its last segment: a rule about quantities is about quantities
     * wherever they sit, the way a redaction rule for "password" covers "lines.42.password".
     */
    private function covers(string $objectType, string $field): bool
    {
        if (\in_array($field, $this->fields, true) || \in_array($objectType.'.'.$field, $this->fields, true)) {
            return true;
        }

        $last = strrchr($field, '.');

        return $last !== false && $last !== '.' && $this->covers($objectType, substr($last, 1));
    }

    /**
     * The value as a number, or null when it is not one — "nothing" counting as zero.
     */
    private static function canonical(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '-') {
            return '0';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value)) {
            // INF and NAN have no quantity to compare: defer, or '1e400' and '9e999'
            // become the same value and a real change disappears from the trail.
            // Otherwise printed with enough digits to round-trip, and then read as
            // text like every other spelling.
            return is_finite($value) ? self::decimal(var_export($value, true)) : null;
        }

        if (\is_string($value) && is_numeric($value)) {
            // As text, digit by digit. Through a float, 9007199254740993 and its
            // neighbour become the same double and a real change disappears from the
            // trail — and a comparator that answers "equal" wrongly deletes history,
            // where one that answers "different" wrongly only adds a record.
            //
            // Trimmed first: is_numeric accepts leading whitespace, so " 12" is a number
            // to PHP and was a different one from "12" here — a record saying a quantity
            // changed from 12 to 12.
            return self::decimal(trim($value));
        }

        return null;
    }

    /**
     * A number's digits, read as text and never through a float.
     *
     * A float has 15–17 significant digits, and everything past them is gone: through
     * one, "9007199254740993e0" and "9007199254740992" are the same value, and so are
     * "0" and "1e-15" once the exponent is flattened to a fixed number of decimals.
     * Either of those is a *false equal*, which deletes a real change from the trail —
     * the failure this whole class is written to avoid. So the exponent is applied by
     * moving the decimal point through the digit string instead: exact, and no wider
     * than the number actually is.
     *
     * @return string|null null when the text is not a number this can read
     */
    private static function decimal(string $number): ?string
    {
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?[eE]([+-]?\d+)$/', $number, $m) !== 1) {
            return str_contains($number, 'e') || str_contains($number, 'E') ? null : self::trim($number);
        }

        [, $sign, $whole, $fraction, $exponent] = $m + [3 => '', 4 => '0'];

        // A quantity nobody could hold: "1e100000000" is well formed and writing it out
        // is a hundred megabytes of zeroes. No opinion costs an extra record at worst;
        // materialising it costs the process.
        if (abs((int) $exponent) > 4096) {
            return null;
        }

        $digits = $whole.$fraction;
        $point = \strlen($whole) + (int) $exponent; // where the decimal point lands

        if ($point <= 0) {
            $digits = str_repeat('0', 1 - $point).$digits;
            $point = 1;
        }

        if ($point > \strlen($digits)) {
            $digits .= str_repeat('0', $point - \strlen($digits));
        }

        return self::trim($sign.substr($digits, 0, $point).'.'.substr($digits, $point));
    }

    /**
     * One spelling per value: "00012.00", "12.000" and "12" are the same quantity, and
     * so are "-0" and "0".
     */
    private static function trim(string $number): string
    {
        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '+-');

        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        $number = ltrim($number, '0');

        if ($number === '' || $number === '.') {
            return '0';
        }

        if (str_starts_with($number, '.')) {
            $number = '0'.$number;
        }

        return $negative ? '-'.$number : $number;
    }
}
