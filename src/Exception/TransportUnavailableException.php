<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Elasticsearch (or the message bus in front of it) could not be reached.
 */
final class TransportUnavailableException extends \RuntimeException implements AuditException
{
    /**
     * The bundle's own words about why an answer cannot be trusted — a truncated bulk
     * response, a view opened without an id, a status with no readable reason. Repeated
     * in full, because the bundle wrote them out of names and numbers, and they are the
     * whole diagnostic.
     */
    public static function saying(string $reason, ?\Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }

    public static function because(\Throwable $previous): self
    {
        // The cause is named, not repeated. It is usually the client's own exception,
        // whose message is the status line followed by the whole response body — and a
        // 429 refusing a document quotes that document. WriteFailedException stopped
        // interpolating a foreign message for exactly this reason; the rule is the same
        // wherever the bundle writes a sentence about somebody else's failure.
        return new self(sprintf('Elasticsearch is unreachable (%s).', $previous::class), 0, $previous);
    }
}
