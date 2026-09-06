<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Writing;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;

/**
 * What counts as a change is the application's question, and this is where it
 * answers it.
 *
 * The default comparison is strict — dates by instant, arrays by value — and a
 * delivery date stored as a `datetime` reports a change whenever the time of day
 * moves, so the history fills with records showing two dates that read the same.
 * Comparing by day stops the record from being written at all, rather than leaving
 * it to be filtered out on the way to a screen.
 *
 * Implementations are picked up automatically and asked in order. They are also
 * asked while a flush is in progress, so keep them deterministic and free of side
 * effects.
 */
final class SameDayComparator implements ValueComparatorInterface
{
    public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
    {
        // Not ours — defer, and the next comparator (or the plain comparison) has
        // its say. Returning false here would be an *answer*: it ends the chain and
        // says "these differ" about every field of every entity, so nothing is ever
        // dropped as noise again. That is the mistake worth naming.
        if ($field !== 'deliverOn') {
            return null;
        }

        if (!$old instanceof \DateTimeInterface || !$new instanceof \DateTimeInterface) {
            return null;
        }

        return $old->format('Y-m-d') === $new->format('Y-m-d');
    }
}
