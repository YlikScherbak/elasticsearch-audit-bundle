<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

/**
 * The counter behind the ids of one millisecond: what makes the records built in it sort by id
 * in the order they were built ({@see RecordId::v7()}).
 *
 * 42 bits, begun at a random point of the lower half, so that whatever it drew there are 2^41
 * ids of headroom before it runs out -- a flush has one moment, and an import of ten thousand
 * records in it is ten thousand ids of one millisecond. Running out is an error, never a quiet
 * new start: a new random start is exactly the id that sorts before the ones already given.
 *
 * Held by the moments that use it ({@see Provenance}) and by nothing else for long: the writer
 * hands every moment of a millisecond the same one while any of them is alive, or while it is the
 * latest millisecond the writer was asked about; one that is neither begins again from a random
 * point.
 *
 * @internal the writer keeps these; nothing outside it builds one but a test
 */
final class IdSequence
{
    public const BITS = 42;

    private int $next;

    /**
     * @param int      $millisecond the millisecond it counts in, as {@see RecordId::millisecondOf()} gives it
     * @param int|null $start       where it begins; random in the lower half when not given
     */
    public function __construct(public readonly int $millisecond, ?int $start = null)
    {
        if ($start !== null && ($start < 0 || $start >= 2 ** self::BITS)) {
            throw new \InvalidArgumentException(sprintf('A sequence begins within its %d bits, not at %d.', self::BITS, $start));
        }

        $this->next = $start ?? random_int(0, 2 ** (self::BITS - 1) - 1);
    }

    /** The next value, and one on: an error once there is none left. */
    public function take(): int
    {
        if ($this->next >= 2 ** self::BITS) {
            throw new \OverflowException(sprintf('The ids of the millisecond %d have run out: more than 2^41 records in it. The record is not given an id that could sort before the ones already given.', $this->millisecond));
        }

        return $this->next++;
    }
}
