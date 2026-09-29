<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

/**
 * Identifiers for audit records: UUID version 7, built from the record's own
 * timestamp, and a stable, unique tiebreaker behind loggedAt, so no two records share a
 * sort position and a cursor never sees the same pair twice.
 *
 * **In the order they were built, within a millisecond of one process** (1.3). The bits
 * after the timestamp are a counter ({@see IdSequence}): the records a process builds in
 * one millisecond sort by id in the order it built them -- one flush's in the order their
 * facts ran, and a flush nested in another and the outer one's too, when both are alive
 * in the same millisecond. A record a frame merged keeps the id and the moment of its
 * first part. What is not promised: an order between processes, whose counters begin at
 * random points; and an order between moments that are not alive together -- a millisecond
 * no moment of this writer holds any more begins again from a random point.
 *
 * 48 bits of milliseconds, 4 of version, 42 of counter around the 2 of variant, 32 random:
 * the counter begins at a random point of its lower half, so it runs out only past 2^41
 * records of one millisecond, and then the record gets no id rather than one that sorts
 * before the others ({@see IdSequence::take()}). The 32 random bits and the counter's random
 * start keep two processes apart: two sequences begun independently in one millisecond
 * share a first id with a probability of 2^-73. A record with no moment of the writer's --
 * one whose loggedAt the caller set to another millisecond -- gets the random bits of
 * before, 74 of them, and no order.
 *
 * What it does not promise is that a cursor over a *growing* index sees everything.
 * A record written after a page was read, in a millisecond that page already covered,
 * may get an id that sorts before the cursor's position -- another process's, or a later
 * moment's -- and a reader paging forward will not meet it. Paging is exact over a result
 * set that is not moving, which is what `iterate(consistent: true)` gives, and near-exact
 * over a live index, where the uncertainty is one millisecond wide at the boundary of a page.
 *
 * Built from when the change happened, not from when the record is written -- so the
 * records of one flush share a millisecond, and the counter is what orders them. What the
 * writing moment is good for is answering "how late is this", and that is the writtenAt field.
 *
 * Known before the write, so a retried write (Messenger redelivering after a
 * timeout) overwrites its own document instead of adding a second one.
 *
 * @internal the writer assigns record ids; set one yourself with AuditRecord::withId() if you have a natural one
 */
final class RecordId
{
    /**
     * @param IdSequence|null $sequence the counter of the record's millisecond: the id is its
     *                                  next value, where it counts in that millisecond; random
     *                                  bits otherwise
     */
    public static function v7(\DateTimeImmutable $at, ?IdSequence $sequence = null): string
    {
        $ms = self::millisecondOf($at);

        if ($sequence !== null && $sequence->millisecond === $ms) {
            // 48 bits of milliseconds, 4 of version (7), the counter's upper 12 bits in
            // rand_a, 2 of variant, its lower 30 bits, then 32 random bits:
            // 48 + 4 + 12 + 2 + 30 + 32 = 128. The counter's bits sit where they sort, above
            // the random ones, so the ids of one sequence sort in the order it gave them.
            $counter = $sequence->take();
            $hex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT)
                .'7'.sprintf('%03x', $counter >> 30)
                .sprintf('%08x', (0x2 << 30) | ($counter & 0x3FFFFFFF))
                .bin2hex(random_bytes(4));

            return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
        }

        $random = random_bytes(10);

        // 48 bits of milliseconds, 4 bits of version, 12 random bits (hex 0-2), 2 bits of
        // variant (the low bits of byte 1, hex 3, used nowhere else), 62 random bits (hex 4-18).
        $hex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT)
            .'7'.substr(bin2hex($random), 0, 3)
            .dechex(0x8 | (\ord($random[1]) & 0x3)).substr(bin2hex($random), 4, 15);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /**
     * The millisecond an id of this moment carries.
     *
     * The timestamp field is 48 unsigned bits: a record dated before 1970 (imported history, a
     * corrupt source date read leniently) pins to the epoch — the order of prehistory does not
     * matter, a malformed id would. The far end matches: the field runs out in the year 10889
     * either way.
     */
    public static function millisecondOf(\DateTimeImmutable $at): int
    {
        return max(0, min((int) $at->format('Uv'), 2 ** 48 - 1));
    }
}
