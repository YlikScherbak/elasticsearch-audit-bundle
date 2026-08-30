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
            return self::trim(sprintf('%.14F', $value));
        }

        if (\is_string($value) && is_numeric($value)) {
            // As text, digit by digit. Through a float, 9007199254740993 and its
            // neighbour become the same double and a real change disappears from the
            // trail — and a comparator that answers "equal" wrongly deletes history,
            // where one that answers "different" wrongly only adds a record.
            return str_contains($value, 'e') || str_contains($value, 'E')
                ? self::trim(sprintf('%.14F', (float) $value))
                : self::trim($value);
        }

        return null;
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
