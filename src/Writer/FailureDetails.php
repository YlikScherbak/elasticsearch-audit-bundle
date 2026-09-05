<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

use Borsche\ElasticsearchAuditBundle\Exception\FailureReason;
use Borsche\ElasticsearchAuditBundle\Exception\SafeExceptionMessage;

/**
 * How much of a failure's cause the bundle repeats in what it emits — the log line,
 * the PSR-3 context, RecordFailedEvent.
 *
 * The two needs here are both real and they pull apart. An operator wants the reason
 * a write failed, in the log, without catching anything. An application that
 * configured redaction has said that certain values must never be kept — and the
 * cluster's own exception may quote one of them back ("failed to parse field [email]
 * … 'alice@example.com'"), as may an enricher's, as may any library's. Copying that
 * into a log line puts the value in the one place redaction was meant to keep it out
 * of.
 *
 * So it is a choice, with the default following the declaration the application has
 * already made: redaction configured means Cause, no redaction means Full. Either can
 * be set explicitly (redact.failure_details).
 */
enum FailureDetails: string
{
    /**
     * The cause's message is repeated. What most applications want, and what the
     * bundle did before it had this choice.
     */
    case Full = 'full';

    /**
     * The cause is named by class, and only messages the bundle wrote itself are
     * repeated — those are built from class names, field names and statuses, never
     * from a value. The original stays reachable through getPrevious().
     */
    case Cause = 'cause';

    /**
     * What may be shown for this cause under this policy.
     */
    public function of(\Throwable $e): \Throwable
    {
        if ($this === self::Full) {
            return $e;
        }

        if ($e instanceof SafeExceptionMessage) {
            // The message is safe because the class says so. The chain hanging off it is
            // not covered by that promise and is walked by everything that serialises an
            // exception, so a safe sentence with somebody else's exception behind it is
            // repeated without the somebody else.
            return $e->getPrevious() === null ? $e : FailureReason::keepingTheMessageOf($e);
        }

        return FailureReason::insteadOf($e);
    }
}
