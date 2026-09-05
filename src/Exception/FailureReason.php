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
    public static function insteadOf(\Throwable $cause): self
    {
        return new self(sprintf('%s (its message is not repeated here; catch WriteFailedException and read getPrevious() for the full cause)', $cause::class));
    }
}
