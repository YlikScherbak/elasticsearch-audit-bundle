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

        $before = self::toNumber($old);
        $after = self::toNumber($new);

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
    private static function toNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-') {
            return 0.0;
        }

        if (\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        return null;
    }
}
