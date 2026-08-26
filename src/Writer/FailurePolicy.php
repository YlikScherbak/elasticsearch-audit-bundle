<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

/**
 * What happens when a record cannot be written.
 *
 * Log (the default): the failure is logged and the caller carries on. An audit log
 * must never take the business operation down with it — losing one history entry
 * is better than losing the order that entry was about.
 *
 * Throw: the failure surfaces as a WriteFailedException. For the cases where a
 * missing audit entry is worse than a failed operation (compliance logs).
 */
enum FailurePolicy: string
{
    case Log = 'log';
    case Throw = 'throw';
}
