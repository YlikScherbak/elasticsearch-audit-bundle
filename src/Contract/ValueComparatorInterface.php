<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

/**
 * Decides, when a frame closes, whether a field ended where it started — in which
 * case the change is dropped as noise.
 *
 * The default comparison is strict (dates by instant, arrays by value). An
 * application whose data treats some values as the same — null, '' and 0 for a
 * stock quantity, say — registers a comparator for those fields. Implementations
 * are picked up automatically and asked in order; the first opinion wins.
 *
 * An opinion is about a field this comparator knows; everything else is deferred:
 *
 *   public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
 *   {
 *       if ($objectType !== 'order' || $field !== 'shippedAt') {
 *           return null;                  // not ours — ask the next one
 *       }
 *
 *       return $this->sameDay($old, $new);
 *   }
 *
 * Returning false for a field it has no opinion about is the mistake worth naming:
 * false is an answer, it ends the chain, and it says "these differ" about every
 * field of every entity — so nothing is ever dropped as noise again and every step
 * of an operation gets a record. The chain is walked once per value, so this is
 * asked often; it is also asked while a flush is in progress, and like a
 * representer it should be deterministic and free of side effects.
 */
interface ValueComparatorInterface
{
    /**
     * null is "no opinion", never an answer: every consumer of the interface falls
     * back to the plain comparison (ValueComparator::same()) when it gets one. The
     * chain the bundle wires (ValueComparator) narrows the return to bool because it
     * ends in that fallback itself — a single link handed in where the chain usually
     * goes must behave the same, which is why the consumers apply the fallback rather
     * than trust the narrower type.
     *
     * @return bool|null true/false when this comparator has an opinion about $field, null to defer
     */
    public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool;
}
