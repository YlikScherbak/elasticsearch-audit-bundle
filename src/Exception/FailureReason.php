<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Stands in for a cause whose message the bundle will not repeat: it names the class
 * and nothing else, and deliberately carries **no previous exception**, so that
 * neither a log processor walking the chain nor a listener reading `getPrevious()`
 * finds the text this exists to keep out of them.
 *
 * The original is not lost — whoever catches WriteFailedException still gets it as
 * that exception's previous, because a caller who catches is a caller who chose to
 * look. What changes is that the bundle stops putting it into channels the
 * application did not ask for: the log line, the PSR-3 context, the failure event.
 */
final class FailureReason extends \RuntimeException implements AuditException, SafeExceptionMessage
{
    /**
     * @param class-string<\Throwable> $causeClass what actually failed, so a listener can
     *                                             still tell a missing index from a refused
     *                                             document without reading any message
     */
    private function __construct(string $message, public readonly string $causeClass)
    {
        parent::__construct($message);
    }

    public static function insteadOf(\Throwable $cause): self
    {
        return new self(sprintf('%s (its message is not repeated here; catch WriteFailedException and read getPrevious() for the full cause)', $cause::class), $cause::class);
    }

    /**
     * The cause's own message, kept because the cause vouched for it, and nothing else
     * of the cause kept at all.
     *
     * SafeExceptionMessage is a promise about a *message*, and the failure path was
     * reading it as a promise about the whole object. An exception can be safe and still
     * carry an unsafe one: IndexNotFoundException names the index, which is the bundle's
     * own word, and the gateway hands it the cluster's exception as previous — where a
     * log processor walking the chain finds precisely the text the safe message avoided.
     */
    public static function keepingTheMessageOf(\Throwable $cause): self
    {
        return new self($cause->getMessage(), $cause::class);
    }
}
