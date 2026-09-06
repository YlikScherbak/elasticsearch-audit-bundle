<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * The index a record was written to, or a query was run against, does not exist.
 * Run `audit:index:create` (or create it yourself with the bundle's mapping).
 */
final class IndexNotFoundException extends \RuntimeException implements AuditException, SafeExceptionMessage
{
    /**
     * Private on purpose: every message this class carries is one the bundle wrote, and
     * that is what lets it be repeated where a foreign message would not be. A public
     * constructor is a way for anybody to put anything into a sentence the bundle
     * vouches for.
     */
    private function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The same refusal without the cause behind it, for the Messenger boundary: the
     * sentence is this class's own, the chain is the client's.
     *
     * @internal
     */
    public static function saying(string $message): self
    {
        return new self($message);
    }

    public static function forIndex(string $index, ?\Throwable $previous = null): self
    {
        return new self(sprintf('The Elasticsearch index "%s" does not exist. Run "audit:index:create" to create it.', $index), 0, $previous);
    }
}
