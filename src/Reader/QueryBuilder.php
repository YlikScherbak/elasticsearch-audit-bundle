<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Reader;

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

        $body = [
            // A query known to match nothing is answered by AuditReader without a
            // request; anything else building the body (raw(), a future caller) still
            // must not turn "known empty" back into a real search.
            'query' => match (true) {
                $query->matchesNothing() => ['match_none' => new \stdClass()],
                $filter === [] => ['match_all' => new \stdClass()],
                default => ['bool' => ['filter' => $filter]],
            },
            'sort' => array_values(array_filter([
                ['loggedAt' => $query->sort],
                ['id' => ['order' => $query->sort, 'unmapped_type' => 'keyword']],
                // A timestamp and an id are unique inside one index. A query across
                // several — any(), which reads every routed index — can meet the same
                // pair twice, since an application may choose its own record ids, and
                // search_after then steps over one of the two: on a live cluster the
                // second document simply never came back. The index name settles it.
                !$pointInTime && $query->objectType === null ? ['_index' => $query->sort] : null,
                $pointInTime ? ['_shard_doc' => $query->sort] : null,
            ])),
            'size' => $query->limit,
            'track_total_hits' => $trackTotalHits,
        ];

        if ($query->usesCursor()) {
            $body['search_after'] = $query->searchAfter;
        } else {
            $body['from'] = $query->offset();
        }

        return $body;
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
