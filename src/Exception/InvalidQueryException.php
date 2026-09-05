<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * An AuditQuery that Elasticsearch could not or should not run: a limit out of
 * range, a page too deep for from/size, an empty filter value, a raw body the reader
 * cannot vouch for. Usually raised while the query is being built, so the caller gets a
 * clear message instead of a 400 — but also for a 4xx the cluster answered a search
 * with, which is the same class of mistake arriving one round trip later.
 */
final class InvalidQueryException extends \InvalidArgumentException implements AuditException
{
}
