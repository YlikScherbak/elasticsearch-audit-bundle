<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;

/**
 * Asks the application's comparators in order and falls back to a strict
 * comparison: dates by instant, arrays by value, everything else by identity.
 *
 * It is a comparator itself, so it fits wherever one is asked for — the frame that
 * merges records and the listener that builds them both take this one. It is the only
 * comparator that always has an opinion: the fallback is its own, so it never defers,
 * which is why equals() returns bool where the interface allows null.
 *
 * @internal the chain behind the comparators; implement ValueComparatorInterface to take part in it
 */
final class ValueComparator implements ValueComparatorInterface
{
    /** @var list<ValueComparatorInterface> */
    private readonly array $comparators;

    /**
     * @param iterable<ValueComparatorInterface> $comparators
     */
    public function __construct(iterable $comparators = [])
    {
        // Read once, for the same reason the writer reads its enrichers once: the chain
        // is walked again for every value compared, and a Generator would be exhausted
        // after the first one — agreeing with nothing, silently, from then on. The
        // parameter stays iterable because a tagged iterator is one; what the class
        // supports and what its signature accepts are now the same thing.
        $this->comparators = \is_array($comparators) ? array_values($comparators) : iterator_to_array($comparators, false);
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
            // With microseconds: getTimestamp() answers in whole seconds, so two moments
            // 800 ms apart were "the same instant" and a change inside one second was
            // never recorded at all.
            return $old->format('U.u') === $new->format('U.u');
        }

        if (\is_array($old) && \is_array($new)) {
            return self::sameArray($old, $new);
        }

        return $old === $new;
    }

    /**
     * Same keys, and every value the same by this comparison — element by element, so
     * "1" and 1 inside a collection snapshot count as the change they are, which PHP's
     * own == between arrays would hide.
     *
     * @param array<mixed> $old
     * @param array<mixed> $new
     */
    private static function sameArray(array $old, array $new): bool
    {
        if (\count($old) !== \count($new)) {
            return false;
        }

        foreach ($old as $key => $value) {
            if (!\array_key_exists($key, $new) || !self::same($value, $new[$key])) {
                return false;
            }
        }

        return true;
    }
}
