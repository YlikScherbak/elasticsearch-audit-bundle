<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Elasticsearch (or the message bus in front of it) could not be reached.
 */
final class TransportUnavailableException extends \RuntimeException implements AuditException
{
    public static function because(\Throwable $previous): self
    {
        return new self('Elasticsearch is unreachable: '.$previous->getMessage(), 0, $previous);
    }
}
