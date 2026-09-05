<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;

/**
 * One condition on one attribute: what AuditQuery keeps in its filters map.
 *
 * A value object rather than the bare scalar-or-list it used to be, because the bare
 * form could say only term/terms — no "has the field", no "does not have it", no
 * range — and its shape was one release away from being frozen into the public API.
 * The kinds here can grow without changing the map's type again.
 */
final class Filter
{
    /**
     * @param bool|int|float|string|null $value  the value of an Is filter
     * @param list<scalar>               $values the values of an In filter
     * @param int|float|string|null      $from   the lower bound of a Between filter
     * @param int|float|string|null      $to     the upper bound of a Between filter
     */
    private function __construct(
        public readonly FilterKind $kind,
        public readonly bool|int|float|string|null $value = null,
        public readonly array $values = [],
        public readonly int|float|string|null $from = null,
        public readonly int|float|string|null $to = null,
    ) {
    }

    /**
     * Exact match on one value.
     */
    public static function is(bool|int|float|string $value): self
    {
        return new self(FilterKind::Is, value: $value);
    }

    /**
     * Any of the given values.
     *
     * @param list<scalar> $values
     */
    public static function in(array $values): self
    {
        if ($values === []) {
            throw new InvalidQueryException('The list of values cannot be empty — leave the filter out to not filter, or use AuditQuery::matchNothing() to answer with an empty page.');
        }

        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                throw new InvalidQueryException(sprintf('A value to filter by is a %s; only strings, numbers and booleans can be.', get_debug_type($value)));
            }
        }

        return new self(FilterKind::In, values: array_values($values));
    }

    /**
     * The document has the field. What "has" means follows Elasticsearch's exists
     * query: a field that was written as null does not count.
     */
    public static function exists(): self
    {
        return new self(FilterKind::Exists);
    }

    /**
     * The document does not have the field — records written before an enricher
     * started adding it, which is what a backfill goes looking for.
     */
    public static function missing(): self
    {
        return new self(FilterKind::Missing);
    }

    /**
     * Inclusive range; either bound may be null for a half-open one. A date bound is
     * stored the way the writer stores dates — UTC, in the index format — so it
     * matches an attribute mapped like loggedAt.
     */
    public static function between(int|float|string|\DateTimeInterface|null $from, int|float|string|\DateTimeInterface|null $to): self
    {
        if ($from === null && $to === null) {
            throw new InvalidQueryException('A range needs at least one bound — leave the filter out to not filter.');
        }

        $lower = self::bound($from);
        $upper = self::bound($to);

        // The same refusal AuditQuery::between() makes for dates. A crossed range cannot
        // match anything, and an empty page is the one answer an audit query must never
        // give by mistake: it reads as "nothing happened".
        //
        // Only bounds of one kind are compared. A keyword field orders its values as
        // text, so "10" against 9 is not a comparison this can make on the caller's
        // behalf, and guessing would refuse queries that are perfectly good.
        if (self::comparable($lower, $upper) && $lower > $upper) {
            throw new InvalidQueryException(sprintf('The lower bound of this range (%s) is after the upper one (%s), so it can match nothing at all.', var_export($lower, true), var_export($upper, true)));
        }

        return new self(FilterKind::Between, from: $lower, to: $upper);
    }

    /**
     * Whether these two bounds can be put in order without guessing what the field is.
     * Two numbers can; two strings can, since a date bound is formatted so that its text
     * order is its chronological order. A number against a string cannot.
     */
    private static function comparable(int|float|string|null $lower, int|float|string|null $upper): bool
    {
        if ($lower === null || $upper === null) {
            return false;
        }

        return \is_string($lower) === \is_string($upper);
    }

    private static function bound(int|float|string|\DateTimeInterface|null $bound): int|float|string|null
    {
        return $bound instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($bound)->setTimezone(new \DateTimeZone('UTC'))->format(AuditRecord::DATE_FORMAT)
            : $bound;
    }
}
