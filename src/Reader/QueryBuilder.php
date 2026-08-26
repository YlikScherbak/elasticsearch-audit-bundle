<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Reader;

use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * Translates an AuditQuery into an Elasticsearch request body.
 *
 * Every condition goes into bool.filter: audit reads are exact matches on keyword
 * and numeric fields, so scoring would only cost time. The sort is loggedAt plus
 * the record id as a tiebreaker: ids are time-ordered UUIDs, so ties within a
 * second resolve in write order, and unlike _doc an id does not move when
 * segments merge — a cursor taken from one page stays valid for the next.
 * unmapped_type keeps reads working on an index created before ids existed.
 */
final class QueryBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(AuditQuery $query): array
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

        foreach ($query->filters as $attribute => $value) {
            $filter[] = \is_array($value) ? self::termOrTerms($attribute, $value) : ['term' => [$attribute => $value]];
        }

        $body = [
            'query' => $filter === [] ? ['match_all' => new \stdClass()] : ['bool' => ['filter' => $filter]],
            'sort' => [
                ['loggedAt' => $query->sort],
                ['id' => ['order' => $query->sort, 'unmapped_type' => 'keyword']],
            ],
            'size' => $query->limit,
            'track_total_hits' => true,
        ];

        if ($query->usesCursor()) {
            $body['search_after'] = $query->searchAfter;
        } else {
            $body['from'] = $query->offset();
        }

        return $body;
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
