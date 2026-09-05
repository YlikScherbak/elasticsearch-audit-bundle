<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

/**
 * Identifiers for audit records: UUID version 7, built from the record's own
 * timestamp. Ordered by the millisecond it carries, and **random within that
 * millisecond** — the bits after the timestamp are random, not a counter, so two
 * ids made in the same millisecond sort in no particular order. That is enough
 * for what it is used for: a stable, unique tiebreaker behind loggedAt, so no two
 * records share a sort position and a cursor never sees the same pair twice. It is
 * not a claim about write order inside a millisecond, and nothing here relies on one.
 *
 * What it does not promise is that a cursor over a *growing* index sees everything.
 * A record written after a page was read, in a millisecond that page already covered,
 * gets a random id that may sort before the cursor's position — and a reader paging
 * forward will not meet it. That is a property of any (time, random) ordering, not of
 * this one: paging is exact over a result set that is not moving, which is what
 * `iterate(consistent: true)` gives, and near-exact over a live index, where the
 * uncertainty is one millisecond wide at the boundary of a page.
 *
 * Known before the write, so a retried write (Messenger redelivering after a
 * timeout) overwrites its own document instead of adding a second one.
 *
 * @internal the writer assigns record ids; set one yourself with AuditRecord::withId() if you have a natural one
 */
final class RecordId
{
    public static function v7(\DateTimeImmutable $at): string
    {
        // The timestamp field is 48 unsigned bits: a record dated before 1970 (imported
        // history, a corrupt source date read leniently) pins to the epoch — the order
        // of prehistory does not matter, a malformed id would. The far end matches: the
        // field runs out in the year 10889 either way.
        $ms = max(0, min((int) $at->format('Uv'), 2 ** 48 - 1));
        $random = random_bytes(10);

        // 48 bits of milliseconds, 4 bits of version, 12 random bits (hex 0-2), 2 bits of
        // variant (the low bits of byte 1, hex 3, used nowhere else), 62 random bits (hex 4-18).
        $hex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT)
            .'7'.substr(bin2hex($random), 0, 3)
            .dechex(0x8 | (\ord($random[1]) & 0x3)).substr(bin2hex($random), 4, 15);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
