<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * The index a record was written to, or a query was run against, does not exist.
 * Run `audit:index:create` (or create it yourself with the bundle's mapping).
 */
final class IndexNotFoundException extends \RuntimeException implements AuditException, SafeExceptionMessage
{
    public static function forIndex(string $index, ?\Throwable $previous = null): self
    {
        return new self(sprintf('The Elasticsearch index "%s" does not exist. Run "audit:index:create" to create it.', $index), 0, $previous);
    }
}
