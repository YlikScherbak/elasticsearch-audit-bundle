<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;

/**
 * What to read from the history. Immutable; every with*() returns a copy.
 *
 * Filters on the base fields have named methods; attributes added by enrichers
 * are filtered with where()/whereIn(). Options carry application-specific
 * parameters (a country, a "mine only" flag) that a QueryExtension turns into
 * real filters — the bundle itself never looks at them.
 *
 * Paging is either page/limit (simple, capped by Elasticsearch at 10 000 rows
 * deep) or searchAfter (cursor-style, unlimited) — see AuditPage::nextCursor().
 */
final class AuditQuery
{
    public const MAX_LIMIT = 1000;
    public const MAX_WINDOW = 10_000; // Elasticsearch's default index.max_result_window

    public const SORT_DESC = 'desc';
    public const SORT_ASC = 'asc';

    /**
     * @param list<int|string>                            $objectIds
     * @param list<string>                                $events
     * @param list<string>                                $actors
     * @param list<string>                                $ids
     * @param array<string, scalar|list<scalar>>          $filters attribute => value(s)
     * @param array<string, mixed>                        $options
     * @param list<mixed>|null                            $searchAfter
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

        return new self($this->objectType, $this->objectIds, $this->events, $this->actors, $this->ids, $from, $to, $this->filters, $this->options, $this->sort, $this->page, $this->limit, $this->searchAfter);
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
     * Repeating an attribute replaces the earlier value, so a QueryExtension can narrow
     * a filter the application already set.
     */
    public function where(string $attribute, bool|int|float|string $value): self
    {
        self::attributeName($attribute);

        return $this->with(filters: array_replace($this->filters, [$attribute => $value]));
    }

    /**
     * @param list<scalar> $values
     */
    public function whereIn(string $attribute, array $values): self
    {
        self::attributeName($attribute);

        return $this->with(filters: array_replace($this->filters, [$attribute => self::nonEmpty(array_values($values), sprintf('values for "%s"', $attribute))]));
    }

    /**
     * An application-specific parameter for a QueryExtension to interpret.
     */
    public function withOption(string $name, mixed $value): self
    {
        return $this->with(options: array_replace($this->options, [$name => $value]));
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return \array_key_exists($name, $this->options);
    }

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

        self::assertLimit($limit);

        if ($page * $limit > self::MAX_WINDOW) {
            throw new InvalidQueryException(sprintf('Page %d with %d per page reaches past row %d, which Elasticsearch does not serve with from/size. Use after() to page with a cursor instead.', $page, $limit, self::MAX_WINDOW));
        }

        return $this->with(page: $page, limit: $limit, searchAfter: null);
    }

    public function limit(int $limit): self
    {
        return $this->page($this->page, $limit);
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

        return $this->with(page: 1, searchAfter: array_values($cursor));
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

        return $values;
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

    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidQueryException(sprintf('The limit must be between 1 and %d, %d given.', self::MAX_LIMIT, $limit));
        }
    }

    /**
     * A copy with the given fields replaced. Dates are handled by between(),
     * since null is a meaningful value for them.
     *
     * @param list<int|string>|null                   $objectIds
     * @param list<string>|null                       $events
     * @param list<string>|null                       $actors
     * @param list<string>|null                       $ids
     * @param array<string, scalar|list<scalar>>|null $filters
     * @param array<string, mixed>|null               $options
     * @param list<mixed>|null                        $searchAfter
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
            // page() resets the cursor; after() sets it; everything else keeps it.
            $searchAfter ?? ($page !== null ? null : $this->searchAfter),
        );
    }
}
