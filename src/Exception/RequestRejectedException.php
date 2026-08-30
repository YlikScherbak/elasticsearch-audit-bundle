<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Elasticsearch answered, but refused the request: a document that does not fit the
 * mapping, an index name it does not accept, missing permissions. Retrying will not
 * help; the request itself has to change.
 *
 * Backpressure is not here. A cluster answering 429 is asking for the same request in
 * a moment, so that is a TransportUnavailableException, which the bundle retries.
 */
final class RequestRejectedException extends \RuntimeException implements AuditException
{
    public static function because(int $status, string $reason, \Throwable $previous): self
    {
        return new self(sprintf('Elasticsearch rejected the request (HTTP %d): %s', $status, $reason), $status, $previous);
    }

    /**
     * Elasticsearch's reason for refusing a document ends with a preview of the value
     * it could not parse. That value is the one thing an audit log's error path must
     * not carry — it may be a person's data, and the log, the failure event and the
     * exception all repeat the reason — so it is cut off here, before anyone sees it.
     */
    public static function withoutValuePreview(string $reason): string
    {
        return rtrim((string) preg_replace("/\\.?\\s*Preview of field's value:.*$/s", '', $reason));
    }
}
