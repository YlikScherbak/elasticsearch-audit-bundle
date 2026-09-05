<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Stands in for a cause whose message the bundle will not repeat: it names the class
 * and nothing else, and deliberately carries **no previous exception**, so that
 * neither a log processor walking the chain nor a listener reading `getPrevious()`
 * finds the text this exists to keep out of them.
 *
 * The original is not carried along either, and that is the point: getPrevious() reads
 * like a private channel and is not one. An uncaught WriteFailedException reaches
 * Symfony's error handler, Monolog's exception processor and Sentry, each of which
 * serialises the whole chain — so attaching the cause would let the policy be walked
 * around by a logger nobody configured for audit. Under `redact.failure_details: full`
 * the cause travels intact, which is what that setting is for; under "cause" it does
 * not travel at all, and $causeClass says what failed.
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
        return new self(sprintf('%s (its message is not repeated: redact.failure_details is "cause", and a foreign message may quote a value the record was redacted of. Set it to "full" to have causes repeated in what the bundle logs, dispatches and raises.)', $cause::class), $cause::class);
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
