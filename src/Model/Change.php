<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * One audited field going from an old value to a new one.
 *
 * Stored in the document as {"old": ..., "new": ...}, which is what a history
 * screen needs to render a diff without knowing anything about the field.
 */
final class Change
{
    public function __construct(
        public readonly mixed $old,
        public readonly mixed $new,
    ) {
    }

    /**
     * @return array{old: mixed, new: mixed}
     */
    public function toArray(): array
    {
        return ['old' => self::normalize($this->old), 'new' => self::normalize($this->new)];
    }

    /**
     * Recognises the array shape a Change is stored as, so documents read back
     * from Elasticsearch (or built by hand) can be handled the same way.
     *
     * The shape is unsealed on purpose: a pair may carry more than its two sides —
     * `changes` takes mixed, so a manual record or an enricher can build one by hand —
     * and a sealed shape here was not only wrong, it was the mental model that let
     * redaction copy those extra keys through unread.
     *
     * @phpstan-assert-if-true array{old: mixed, new: mixed, ...} $value
     */
    public static function isPair(mixed $value): bool
    {
        return \is_array($value) && \array_key_exists('old', $value) && \array_key_exists('new', $value);
    }

    /**
     * Dates are serialised in UTC the way the index mapping expects them (the same
     * form as loggedAt); enums by their value or, for a pure enum, their name — which
     * json_encode would refuse, and a record it refuses is a record lost. Everything
     * else is left to json_encode.
     *
     * A fractional second is kept when there is one, and that is not cosmetic: the
     * comparator that decides whether a date moved reads it to the microsecond, so
     * seconds-only storage produced records whose two sides were the same string. "It
     * changed from 10:00:00 to 10:00:00" is a line that makes a reader distrust the
     * whole trail, and it was the record disagreeing with itself rather than with the
     * database. Nothing here is indexed — `changes` is stored with indexing disabled —
     * so the longer form costs nothing but the characters, and a date without a
     * fractional part is written exactly as it always was.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));

            return $utc->format($utc->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if (\is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
