<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;

/**
 * Where the application turns its own notions into history filters.
 *
 * A history screen filters by things the bundle knows nothing about: "operators
 * of country X", "only my team", "visible to the current user". An extension reads
 * such an option off the query (AuditQuery::option()) — or the current security
 * context — and returns the query with the corresponding real filters applied:
 *
 *   return $query->withActors(...$this->users->idsInCountry($query->option('country')));
 *
 * Extensions run in order for every read, before the query is built. They speak
 * AuditQuery, never Elasticsearch. Implementations are picked up automatically.
 */
interface QueryExtensionInterface
{
    public function extend(AuditQuery $query): AuditQuery;
}
