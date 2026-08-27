<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;

/**
 * Asks the application's comparators in order and falls back to a strict
 * comparison: dates by instant, arrays by value, everything else by identity.
 */
final class ValueComparator
{
    /**
     * @param iterable<ValueComparatorInterface> $comparators
     */
    public function __construct(private readonly iterable $comparators = [])
    {
    }

    public function equals(string $objectType, string $field, mixed $old, mixed $new): bool
    {
        foreach ($this->comparators as $comparator) {
            $opinion = $comparator->equals($objectType, $field, $old, $new);

            if ($opinion !== null) {
                return $opinion;
            }
        }

        return self::same($old, $new);
    }

    public static function same(mixed $old, mixed $new): bool
    {
        if ($old instanceof \DateTimeInterface && $new instanceof \DateTimeInterface) {
            return $old->getTimestamp() === $new->getTimestamp();
        }

        if (\is_array($old) && \is_array($new)) {
            return $old == $new;
        }

        return $old === $new;
    }
}
