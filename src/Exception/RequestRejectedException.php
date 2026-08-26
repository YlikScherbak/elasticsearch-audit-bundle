<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Elasticsearch answered, but refused the request: a document that does not fit
 * the mapping, an index name it does not accept, missing permissions, a rate limit.
 * Retrying will not help; the request itself has to change.
 */
final class RequestRejectedException extends \RuntimeException implements AuditException
{
    public static function because(int $status, string $reason, \Throwable $previous): self
    {
        return new self(sprintf('Elasticsearch rejected the request (HTTP %d): %s', $status, $reason), $status, $previous);
    }
}
