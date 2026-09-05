<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

/**
 * A document Elasticsearch refused, described in the cluster's fields rather than in
 * its prose.
 *
 * The reason text is written for a person to read and is free to change between
 * versions and parsers, and it quotes the refused value: `Preview of field's value:
 * '…'` on 8 and 9, and just as easily `received value [ … ]` or `cannot convert "…" to
 * long` elsewhere. Cutting a known phrase away and passing the rest on is a guarantee
 * that holds only for the wordings somebody has seen — so nothing is passed on. What
 * is kept is `type`, which is machine-readable and says what went wrong without saying
 * what with, and the field name, which is a name the application chose rather than a
 * value a record carried.
 *
 * One class because there are two paths to the same refusal — a single write and an
 * item inside a `_bulk` answer — and they were describing it differently: one
 * structurally, the other by cutting a phrase out of the prose. A privacy boundary
 * that holds on one path and not the other is not one.
 *
 * @internal how the gateway and BulkResult describe a refused document
 */
final class DocumentRefusal
{
    /**
     * @param mixed $type   the error type Elasticsearch named, if it named one
     * @param mixed $reason its own wording — read for the field name and otherwise not repeated
     *
     * @return string|null null when the answer names no type: the caller knows what else it can
     *                     say (a status, an index), and inventing a sentence here would hide that
     */
    public static function describe(mixed $type, mixed $reason): ?string
    {
        if (!\is_string($type) || $type === '') {
            return null;
        }

        $field = self::fieldIn($reason);

        return $field === null
            ? sprintf('%s. Its own wording is not repeated, because a refused document is quoted in it — read the previous exception, or set redact.failure_details to "full".', $type)
            : sprintf('%s on field "%s". The cluster\'s own wording is not repeated, because a refused document is quoted in it — read the previous exception, or set redact.failure_details to "full".', $type, $field);
    }

    /**
     * The field the cluster named, lifted out rather than left in — which is the whole
     * difference from a regex that cuts a phrase away: this takes one recognised
     * fragment and passes nothing else, so a wording it does not know costs a field
     * name and leaks nothing.
     */
    private static function fieldIn(mixed $reason): ?string
    {
        return \is_string($reason) && preg_match('~\bfield \[([^\]]{1,128})\]~', $reason, $found) === 1 ? $found[1] : null;
    }
}
