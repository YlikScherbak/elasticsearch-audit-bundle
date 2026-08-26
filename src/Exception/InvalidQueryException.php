<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * An AuditQuery that Elasticsearch could not or should not run: a limit out of
 * range, a page too deep for from/size, an empty filter value. Thrown while the
 * query is being built, so the caller gets a clear message instead of a 400.
 */
final class InvalidQueryException extends \InvalidArgumentException implements AuditException
{
}
