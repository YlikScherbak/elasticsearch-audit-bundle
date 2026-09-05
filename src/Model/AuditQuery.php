<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;

/**
 * What to read from the history. Immutable; every with*() returns a copy.
 *
 * Filters on the base fields have named methods; attributes added by enrichers
 * are filtered with the where*() family. Options carry application-specific
 * parameters (a country, a "mine only" flag) that a QueryExtension turns into
 * real filters — the bundle itself never looks at them.
 *
 * with*() and where*() REPLACE an earlier filter of the same name: they are for
 * building the query. A QueryExtension almost always means "of what was asked
 * for, only what this viewer may see" — that is narrow*(), which INTERSECTS, and
 * whose empty intersection is an answer (matchNothing(), a page of nothing that
 * costs no request) rather than a filter that quietly widened the result.
 *
 * Paging is either page/limit (simple, capped by Elasticsearch at 10 000 rows
 * deep) or searchAfter (cursor-style, unlimited) — see AuditPage::nextCursor().
 */
final class AuditQuery
{
    /**
     * How large a page may be, and how deep from/size may reach, unless the reader is
     * configured otherwise (reader.max_limit, reader.max_result_window). They are
     * defaults rather than absolutes because both are properties of the deployment —
     * the second one has to match the cluster's own index.max_result_window — and the
     * reader is what knows them. A query is checked against them when it is read.
     */
    public const DEFAULT_MAX_LIMIT = 1000;
    public const DEFAULT_MAX_WINDOW = 10_000; // Elasticsearch's default index.max_result_window

    /**
     * How many values one filter list may carry — Elasticsearch's default
     * index.max_terms_count, which a cluster may raise. Refusing here names the
     * setting; the alternative is the cluster refusing the whole search a round trip
     * later, in its own words. Raised on the cluster and still needed here, filter in
     * batches: a list this long is usually a join that belongs on the other side.
     */
    public const DEFAULT_MAX_TERMS = 65_536;

    public const SORT_DESC = 'desc';
    public const SORT_ASC = 'asc';

    /**
     * @param list<int|string>                            $objectIds
     * @param list<string>                                $events
     * @param list<string>                                $actors
     * @param list<string>                                $ids
     * @param array<string, Filter>                       $filters attribute => condition
     * @param array<string, mixed>                        $options
     * @param list<mixed>|null                            $searchAfter
     * @param bool                                        $nothing the query is known to match nothing — see matchNothing()
     */
    private function __construct(
        public readonly ?string $objectType = null,
        public readonly array $objectIds = [],
        public readonly array $events = [],
        public readonly array $actors = [],
        public readonly array $ids = [],
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to = null,
        public readonly array $filters = [],
        public readonly array $options = [],
        public readonly string $sort = self::SORT_DESC,
        public readonly int $page = 1,
        public readonly int $limit = 20,
        public readonly ?array $searchAfter = null,
        public readonly bool $nothing = false,
    ) {
    }

    /**
     * History of one object type — the usual entry point, and what decides the index.
     */
    public static function for(string $objectType): self
    {
        if ($objectType === '') {
            throw new InvalidQueryException('The object type cannot be empty.');
        }

        return new self(objectType: $objectType);
    }

    /**
     * Across every object type in the default index.
     */
    public static function any(): self
    {
        return new self();
    }

    /**
     * @param int|string ...$ids
     */
    public function withObjectIds(int|string ...$ids): self
    {
        return $this->with(objectIds: self::nonEmpty(array_values($ids), 'object ids'));
    }

    public function withObjectId(int|string $id): self
    {
        return $this->withObjectIds($id);
    }

    public function withEvents(string ...$events): self
    {
        return $this->with(events: self::nonEmpty(array_values($events), 'events'));
    }

    public function withActors(string ...$actors): self
    {
        return $this->with(actors: self::nonEmpty(array_values($actors), 'actors'));
    }

    /**
     * Specific documents by their Elasticsearch _id.
     */
    public function withIds(string ...$ids): self
    {
        return $this->with(ids: self::nonEmpty(array_values($ids), 'ids'));
    }

    public function between(?\DateTimeInterface $from, ?\DateTimeInterface $to): self
    {
        $from = $from === null ? null : \DateTimeImmutable::createFromInterface($from);
        $to = $to === null ? null : \DateTimeImmutable::createFromInterface($to);

        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidQueryException('The "from" date is after the "to" date.');
        }

        // The cursor goes with it. A date bound decides what the query matches, exactly
        // like a filter does, and a cursor taken before it was applied points into a
        // result set that no longer exists.
        return new self($this->objectType, $this->objectIds, $this->events, $this->actors, $this->ids, $from, $to, $this->filters, $this->options, $this->sort, $this->page, $this->limit, null, $this->nothing);
    }

    public function since(\DateTimeInterface $from): self
    {
        return $this->between($from, $this->to);
    }

    public function until(\DateTimeInterface $to): self
    {
        return $this->between($this->from, $to);
    }

    /**
     * Exact match on an attribute added by an enricher (a keyword, integer, boolean...).
     * Repeating an attribute REPLACES the earlier value — this builds the query. An
     * extension that means "only what this viewer may see" wants narrowIn(), whose
     * replacement cannot widen.
     */
    public function where(string $attribute, bool|int|float|string $value): self
    {
        self::attributeName($attribute);

        return $this->withFilter($attribute, Filter::is($value));
    }

    /**
     * @param list<scalar> $values
     */
    public function whereIn(string $attribute, array $values): self
    {
        self::attributeName($attribute);

        return $this->withFilter($attribute, Filter::in(self::scalars($attribute, $values)));
    }

    /**
     * Only records that have the attribute. A field written as null does not count as
     * present — this follows Elasticsearch's own exists query.
     */
    public function whereExists(string $attribute): self
    {
        self::attributeName($attribute);

        return $this->withFilter($attribute, Filter::exists());
    }

    /**
     * Only records that do not have the attribute — the ones written before an
     * enricher started adding it, which is what a backfill goes looking for.
     */
    public function whereNotExists(string $attribute): self
    {
        self::attributeName($attribute);

        return $this->withFilter($attribute, Filter::missing());
    }

    /**
     * Inclusive range on an attribute; either bound may be null for a half-open one.
     * A date bound is matched the way the writer stores dates (UTC, index format).
     */
    public function whereBetween(string $attribute, int|float|string|\DateTimeInterface|null $from, int|float|string|\DateTimeInterface|null $to): self
    {
        self::attributeName($attribute);

        return $this->withFilter($attribute, Filter::between($from, $to));
    }

    /**
     * Of the object ids already asked for, only these — the intersection, where
     * withObjectIds() would replace. For QueryExtensions: a visibility boundary that
     * turns out disjoint from the client's request answers with matchNothing(), never
     * with more than was asked. Values compare the way Elasticsearch matches them:
     * "5" and 5 are the same id.
     */
    public function narrowObjectIds(int|string ...$ids): self
    {
        $ids = self::nonEmpty(array_values($ids), 'object ids');

        if ($this->objectIds === []) {
            return $this->with(objectIds: $ids);
        }

        $kept = array_values(array_intersect($this->objectIds, $ids));

        return $kept === [] ? $this->matchNothing() : $this->with(objectIds: $kept);
    }

    /**
     * The same intersection for actors.
     */
    public function narrowActors(string ...$actors): self
    {
        $actors = self::nonEmpty(array_values($actors), 'actors');

        if ($this->actors === []) {
            return $this->with(actors: $actors);
        }

        $kept = array_values(array_intersect($this->actors, $actors));

        return $kept === [] ? $this->matchNothing() : $this->with(actors: $kept);
    }

    /**
     * The same intersection for an attribute: of the values already allowed, only
     * these. Over exists() the list simply stands (a record with one of the values has
     * the field); over missing() nothing can match; a range cannot be narrowed by a
     * list and is refused.
     *
     * @param list<scalar> $values
     */
    public function narrowIn(string $attribute, array $values): self
    {
        self::attributeName($attribute);
        $values = self::scalars($attribute, $values);
        $current = $this->filters[$attribute] ?? null;

        if ($current === null || $current->kind === FilterKind::Exists) {
            return $this->withFilter($attribute, Filter::in($values));
        }

        if ($current->kind === FilterKind::Missing) {
            return $this->matchNothing(); // no value and one of these values at once
        }

        if ($current->kind === FilterKind::Is) {
            return \in_array(self::comparable($current->value), array_map(self::comparable(...), $values), true) ? $this : $this->matchNothing();
        }

        if ($current->kind === FilterKind::In) {
            $allowed = array_map(self::comparable(...), $values);
            $kept = array_values(array_filter($current->values, static fn (mixed $value): bool => \in_array(self::comparable($value), $allowed, true)));

            return $kept === [] ? $this->matchNothing() : $this->withFilter($attribute, Filter::in($kept));
        }

        throw new InvalidQueryException(sprintf('"%s" is filtered by a range; a list of values cannot narrow it — set the bounds instead.', $attribute));
    }

    /**
     * A query known to match nothing: the reader answers it with an empty page and no
     * request at all. This is what an empty narrow*() intersection becomes — the state
     * that used to need a made-up id nobody has, typed to fit the field's mapping.
     *
     * Sticky by design: once an extension has said "this viewer sees none of it", no
     * later with*() or narrow*() in the chain may widen the answer back open.
     */
    public function matchNothing(): self
    {
        return $this->with(nothing: true);
    }

    public function matchesNothing(): bool
    {
        return $this->nothing;
    }

    /**
     * An application-specific parameter for a QueryExtension to interpret.
     *
     * Scalars, null, and arrays of those. An option is part of what a query matches —
     * an extension reads it and narrows accordingly — so it is part of the fingerprint
     * a cursor token carries, and a value that cannot be written down the same way twice
     * cannot be in one. An object would also have made the fingerprint throw, and it is
     * computed after the search has already been run: a page that Elasticsearch answered
     * would have failed on the way back.
     */
    public function withOption(string $name, mixed $value): self
    {
        self::assertOptionIsCanonical($name, $value);

        return $this->with(options: array_replace($this->options, [$name => $value]));
    }

    private static function assertOptionIsCanonical(string $name, mixed $value, int $depth = 0): void
    {
        if ($value === null || \is_scalar($value)) {
            return;
        }

        if (\is_array($value) && $depth < 8) {
            foreach ($value as $item) {
                self::assertOptionIsCanonical($name, $item, $depth + 1);
            }

            return;
        }

        throw new InvalidQueryException(sprintf('The query option "%s" is %s. An option decides what a query matches, so it travels in the fingerprint a cursor token carries and has to be something that reads the same way every time: a scalar, null, or an array of those%s. Pass an id or a name rather than the object itself.', $name, get_debug_type($value), \is_array($value) ? ', nested no deeper than 8 levels' : ''));
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return \array_key_exists($name, $this->options);
    }

    /**
     * Oldest entries first. Reaching for a direction abandons a cursor the query holds
     * (unless it already points that way): its sort values belong to the ordering that
     * produced them, and continuing against the other one skips or repeats records.
     */
    public function oldestFirst(): self
    {
        return $this->with(sort: self::SORT_ASC);
    }

    public function newestFirst(): self
    {
        return $this->with(sort: self::SORT_DESC);
    }

    public function page(int $page, int $limit = 20): self
    {
        if ($page < 1) {
            throw new InvalidQueryException('The page number starts at 1.');
        }

        if ($limit < 1) {
            throw new InvalidQueryException(sprintf('The page size must be at least 1, %d given.', $limit));
        }

        return $this->with(page: $page, limit: $limit, searchAfter: null);
    }

    /**
     * How many entries one page or one cursor batch holds. Unlike page(), this keeps a
     * cursor: after($c)->limit(50) continues where the cursor left off, fifty at a time.
     * Reaching for a page number is what abandons the cursor, because a row number and a
     * position in a sorted stream cannot both be where the next entries come from.
     */
    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidQueryException(sprintf('The page size must be at least 1, %d given.', $limit));
        }

        return $this->with(limit: $limit);
    }

    /**
     * Continue after the entry a previous page ended with (AuditPage::nextCursor()).
     *
     * @param array<mixed> $cursor the sort values of that entry, in order; keys are ignored
     */
    public function after(array $cursor): self
    {
        if ($cursor === []) {
            throw new InvalidQueryException('The cursor is empty.');
        }

        // A null in the tuple is Elasticsearch saying the document has no value for that
        // sort field, which for this sort means a record written before the bundle gave
        // records ids. Two of those in one index, saved in the same second, sort by
        // nothing at all: search_after cannot tell them apart and steps over one. The
        // tuple was accepted so those pages would not be stranded — but a page that may
        // silently be missing a record is not a page an audit trail can hand out.
        foreach ($cursor as $value) {
            if ($value === null) {
                throw new InvalidQueryException('This cursor has no value for one of the fields it sorts by, which means a record from before audit records carried ids. Records like that have no order Elasticsearch can continue from — two written in the same second are indistinguishable to search_after, and one of them would be stepped over — so they are paged by page number, or reindexed with ids first.');
            }
        }

        return $this->with(page: 1, searchAfter: array_values($cursor));
    }

    /**
     * The same, from the string form a client was given (AuditPage::nextCursorToken()).
     *
     * @throws InvalidQueryException the token is not one the reader handed out
     */
    public function afterToken(string $token): self
    {
        $continued = $this->after(Cursor::decode($token));
        $continued->continuing = Cursor::queryOf($token);

        return $continued;
    }

    /**
     * What this query matches and in what order, as one string.
     *
     * A cursor is a position inside a result set and means nothing in another one:
     * Elasticsearch takes any search_after whose sort has the right shape and answers
     * with what follows that position wherever it is used, so a token from "all events"
     * continued into "remove events" skips every remove before it without a word. The
     * with*() family drops a cursor when the query changes underneath it, which covers
     * `after($c)->withEvents(...)`; a token arrives the other way round, already
     * detached, and only what it carries can say where it belongs.
     *
     * Paging is deliberately not part of it: how large a page is, and how far into the
     * result set a reader has got, do not change which records are in it.
     */
    public function fingerprint(): string
    {
        return hash('xxh128', serialize([
            $this->objectType,
            $this->objectIds,
            $this->events,
            $this->actors,
            $this->ids,
            $this->from?->format(\DATE_ATOM),
            $this->to?->format(\DATE_ATOM),
            array_map(static fn (Filter $filter): array => (array) $filter, $this->filters),
            $this->options,
            $this->sort,
            $this->nothing,
        ]));
    }

    /**
     * The query a token being continued was taken from, when this query came from one.
     *
     * Not readonly and not in the constructor: it belongs to the act of continuing
     * rather than to what the query matches, and it must not survive a with*() — which
     * builds a different query, and therefore a different result set.
     */
    private ?string $continuing = null;

    public function continuedQuery(): ?string
    {
        return $this->continuing;
    }

    public function usesCursor(): bool
    {
        return $this->searchAfter !== null;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /**
     * @template T
     *
     * @param list<T> $values
     *
     * @return list<T>
     */
    private static function nonEmpty(array $values, string $what): array
    {
        if ($values === []) {
            throw new InvalidQueryException(sprintf('The list of %s cannot be empty — leave the filter out to not filter.', $what));
        }

        if (\count($values) > self::DEFAULT_MAX_TERMS) {
            throw new InvalidQueryException(sprintf('%d %s is past what one terms query accepts by default (%d, Elasticsearch\'s index.max_terms_count) — filter tighter, or query in batches.', \count($values), $what, self::DEFAULT_MAX_TERMS));
        }

        return $values;
    }

    /**
     * How two filter values are told apart when narrowing intersects them.
     *
     * Numbers and their spelling are the same value: Elasticsearch matches "5" against
     * a numeric field, and an id arriving as a string from the HTTP layer is the common
     * case. A boolean is not: PHP's string comparison makes true, 1 and "1" one value,
     * while Elasticsearch keeps a boolean field and a numeric one apart — and a
     * visibility boundary is the last place to guess, so booleans live in a namespace
     * of their own.
     */
    private static function comparable(mixed $value): string
    {
        return \is_bool($value) ? 'bool:'.($value ? '1' : '0') : 'scalar:'.$value;
    }

    private function withFilter(string $attribute, Filter $filter): self
    {
        return $this->with(filters: array_replace($this->filters, [$attribute => $filter]));
    }

    /**
     * What whereIn()/narrowIn() accept: a non-empty list of scalars, refused at the
     * boundary rather than one round trip later in Elasticsearch's words.
     *
     * @param list<scalar> $values
     *
     * @return list<scalar>
     */
    private static function scalars(string $attribute, array $values): array
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                throw new InvalidQueryException(sprintf('A value to filter "%s" by is a %s; only strings, numbers and booleans can be.', $attribute, get_debug_type($value)));
            }
        }

        return self::nonEmpty(array_values($values), sprintf('values for "%s"', $attribute));
    }

    private static function attributeName(string $attribute): void
    {
        if ($attribute === '') {
            throw new InvalidQueryException('The attribute name cannot be empty.');
        }

        if (\in_array($attribute, AuditRecord::reservedFields(), true)) {
            throw new InvalidQueryException(sprintf('"%s" is a base field; filter it with the dedicated method (withObjectIds, withEvents, withActors, between...).', $attribute));
        }
    }

    /**
     * A copy with the given fields replaced. Dates are handled by between(),
     * since null is a meaningful value for them.
     *
     * @param list<int|string>|null      $objectIds
     * @param list<string>|null          $events
     * @param list<string>|null          $actors
     * @param list<string>|null          $ids
     * @param array<string, Filter>|null $filters
     * @param array<string, mixed>|null  $options
     * @param list<mixed>|null           $searchAfter
     */
    private function with(
        ?array $objectIds = null,
        ?array $events = null,
        ?array $actors = null,
        ?array $ids = null,
        ?array $filters = null,
        ?array $options = null,
        ?string $sort = null,
        ?int $page = null,
        ?int $limit = null,
        ?array $searchAfter = null,
        ?bool $nothing = null,
    ): self {
        return new self(
            $this->objectType,
            $objectIds ?? $this->objectIds,
            $events ?? $this->events,
            $actors ?? $this->actors,
            $ids ?? $this->ids,
            $this->from,
            $this->to,
            $filters ?? $this->filters,
            $options ?? $this->options,
            $sort ?? $this->sort,
            $page ?? $this->page,
            $limit ?? $this->limit,
            // A cursor belongs to the query that produced it, and only to that one.
            // Elasticsearch accepts a search_after against anything whose sort has the
            // right shape, and answers with what follows that position *in the new
            // result set* — so a cursor from "all events" continued into "remove events"
            // skips every remove before it and says nothing about having done so.
            // after() sets it; a page number replaces it; changing the ordering, or
            // anything that decides what the query matches — a filter, a date bound, an
            // option a QueryExtension reads — abandons it.
            $searchAfter ?? ($this->stillTheSameSearch($objectIds, $events, $actors, $ids, $filters, $options, $sort, $page, $nothing) ? $this->searchAfter : null),
            // Nothing is sticky: matchNothing() sets it, and nothing unsets it — a
            // later filter in an extension chain must not widen what an earlier
            // extension closed.
            $nothing ?? $this->nothing,
        );
    }

    /**
     * Whether this change leaves the query matching the same records in the same order —
     * the only case where a cursor may survive it.
     *
     * limit() says how many rows to take, not where from, and restating the direction
     * the query already has changes nothing; both keep it.
     *
     * @param list<int|string>|null      $objectIds
     * @param list<string>|null          $events
     * @param list<string>|null          $actors
     * @param list<string>|null          $ids
     * @param array<string, Filter>|null $filters
     * @param array<string, mixed>|null  $options
     */
    private function stillTheSameSearch(?array $objectIds, ?array $events, ?array $actors, ?array $ids, ?array $filters, ?array $options, ?string $sort, ?int $page, ?bool $nothing): bool
    {
        return $page === null
            && $objectIds === null
            && $events === null
            && $actors === null
            && $ids === null
            && $filters === null
            && $options === null
            && $nothing === null
            && ($sort === null || $sort === $this->sort);
    }
}
