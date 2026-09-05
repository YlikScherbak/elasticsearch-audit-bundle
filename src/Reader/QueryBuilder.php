<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Reader;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Filter;
use Borsche\ElasticsearchAuditBundle\Model\FilterKind;

/**
 * Translates an AuditQuery into an Elasticsearch request body.
 *
 * Every condition goes into bool.filter: audit reads are exact matches on keyword
 * and numeric fields, so scoring would only cost time. The sort is loggedAt plus
 * the record id as a tiebreaker: ids are time-ordered UUIDs, so ties within a
 * second resolve in write order, and unlike _doc an id does not move when
 * segments merge — a cursor taken from one page stays valid for the next.
 * unmapped_type keeps reads working on an index created before ids existed.
 *
 * @internal the translation from AuditQuery to a request body; use AuditReader
 */
final class QueryBuilder
{
    /**
     * @param bool $pointInTime the body is for a search inside a point in time: Elasticsearch
     *                          then offers _shard_doc, the tiebreaker it recommends for deep
     *                          paging there, and it goes last so the record id still decides first
     *
     * @return array<string, mixed>
     */
    public function build(AuditQuery $query, bool $pointInTime = false, bool $trackTotalHits = true): array
    {
        $body = [
            'query' => $this->buildQuery($query),
            'sort' => array_values(array_filter([
                ['loggedAt' => $query->sort],
                ['id' => ['order' => $query->sort, 'unmapped_type' => 'keyword']],
                // A timestamp and an id are unique inside one index, and nothing here
                // knows the query reads only one. It reads one *route*, and a route is
                // an alias: an append-only trail rolls over, and the alias then spans the
                // whole series — the ordinary shape, not the exotic one. Two records
                // sharing a timestamp and an id, which they can because an application
                // may choose its own, then sit in different indices and search_after
                // steps over one of them: on a live cluster the second document simply
                // never came back. Keying this on "no object type" read the tuple as
                // unique whenever a name was given, which was never what made it unique.
                !$pointInTime ? ['_index' => $query->sort] : null,
                $pointInTime ? ['_shard_doc' => $query->sort] : null,
            ])),
            'size' => $query->limit,
            'track_total_hits' => $trackTotalHits,
        ];

        $searchAfter = $query->searchAfter;

        if ($searchAfter !== null) {
            // Elasticsearch refuses a search_after that is not exactly as long as the
            // sort, with its own words about both. A token issued while the sort had a
            // different shape — before the index name joined the tuple, or from a
            // point-in-time read — is the way a caller gets there, and "start again"
            // is the only answer; it is worth saying so rather than passing the cluster's
            // sentence on.
            if (\count($searchAfter) !== \count($body['sort'])) {
                throw new InvalidQueryException(sprintf('This cursor token carries %d sort value(s) and this query sorts by %d. The token was issued for a different sort — by an older version of the bundle, or by a consistent read — so it cannot be continued here: start from the first page.', \count($searchAfter), \count($body['sort'])));
            }

            // The count no longer separates the two shapes: a plain search ends its sort
            // with _index and a point-in-time read with _shard_doc, and both tuples are
            // the same length. What still separates them is the value — an index name is
            // a string, a shard-doc position is an integer — and a tuple from a
            // traversal (AuditEntry::$sort, which is public, handed to after()) would
            // otherwise be a valid-looking position in a search it does not belong to.
            $last = $searchAfter[array_key_last($searchAfter)];

            if ($pointInTime !== \is_int($last)) {
                throw new InvalidQueryException($pointInTime
                    ? 'This cursor ends with an index name, so it came from an ordinary search, and a point-in-time read sorts by a position inside its own view instead. The two cannot be continued into each other: start the traversal without a cursor.'
                    : 'This cursor ends with a position inside a point in time (_shard_doc), which exists only inside the view iterate() opened and means nothing in an ordinary search. Page with the token AuditPage::nextCursorToken() gives you, or start from the first page.');
            }

            $body['search_after'] = $searchAfter;
        } else {
            $body['from'] = $query->offset();
        }

        return $body;
    }

    /**
     * What the query matches, and nothing about how it is paged or sorted.
     *
     * `raw()` needs exactly this — the visibility boundary it wraps a caller's body in —
     * and used to take it as `build($query)['query']`, which builds a whole body to
     * throw all but one key of it away. That is not only waste: the boundary is the one
     * thing that must be there, and reaching for it by key means a `build()` that ever
     * stops writing that key hands `null` to a caller wrapping nothing around nothing.
     *
     * @return array<string, mixed>
     */
    public function buildQuery(AuditQuery $query): array
    {
        $filter = [];

        if ($query->objectType !== null) {
            $filter[] = ['term' => ['objectType' => $query->objectType]];
        }

        foreach (['objectId' => $query->objectIds, 'event' => $query->events, 'source' => $query->actors] as $field => $values) {
            if ($values !== []) {
                $filter[] = self::termOrTerms($field, $values);
            }
        }

        if ($query->ids !== []) {
            $filter[] = ['ids' => ['values' => $query->ids]];
        }

        if ($query->from !== null || $query->to !== null) {
            $range = [];

            if ($query->from !== null) {
                $range['gte'] = $query->from->setTimezone(new \DateTimeZone('UTC'))->format(AuditRecord::DATE_FORMAT);
            }

            if ($query->to !== null) {
                $range['lte'] = $query->to->setTimezone(new \DateTimeZone('UTC'))->format(AuditRecord::DATE_FORMAT);
            }

            $filter[] = ['range' => ['loggedAt' => $range]];
        }

        foreach ($query->filters as $attribute => $condition) {
            $filter[] = self::clause((string) $attribute, $condition);
        }

        // A query known to match nothing is answered by AuditReader without a request;
        // anything else building the body (raw(), a future caller) still must not turn
        // "known empty" back into a real search.
        return match (true) {
            $query->matchesNothing() => ['match_none' => new \stdClass()],
            $filter === [] => ['match_all' => new \stdClass()],
            default => ['bool' => ['filter' => $filter]],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function clause(string $field, Filter $filter): array
    {
        return match ($filter->kind) {
            FilterKind::Is => ['term' => [$field => $filter->value]],
            FilterKind::In => self::termOrTerms($field, $filter->values),
            FilterKind::Exists => ['exists' => ['field' => $field]],
            FilterKind::Missing => ['bool' => ['must_not' => [['exists' => ['field' => $field]]]]],
            FilterKind::Between => ['range' => [$field => array_filter(['gte' => $filter->from, 'lte' => $filter->to], static fn (int|float|string|null $bound) => $bound !== null)]],
        };
    }

    /**
     * @param list<scalar> $values
     *
     * @return array<string, mixed>
     */
    private static function termOrTerms(string $field, array $values): array
    {
        return \count($values) === 1
            ? ['term' => [$field => $values[0]]]
            : ['terms' => [$field => $values]];
    }
}
