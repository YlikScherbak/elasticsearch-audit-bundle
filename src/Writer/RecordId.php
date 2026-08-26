<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

/**
 * Identifiers for audit records: UUID version 7, built from the record's own
 * timestamp. Time-ordered, so sorting by id within one second keeps records in
 * the order they were written — which is what makes it the tiebreaker behind
 * loggedAt for cursor paging. And known before the write, so a retried write
 * (Messenger redelivering after a timeout) overwrites its own document instead
 * of adding a second one.
 */
final class RecordId
{
    public static function v7(\DateTimeImmutable $at): string
    {
        $ms = (int) $at->format('Uv');
        $random = random_bytes(10);

        // 48 bits of milliseconds, 4 bits of version, 12 random bits, 2 bits of variant, 62 random bits.
        $hex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT)
            .'7'.substr(bin2hex($random), 0, 3)
            .dechex(0x8 | (\ord($random[2]) & 0x3)).substr(bin2hex($random), 5, 3)
            .substr(bin2hex($random), 8, 12);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
