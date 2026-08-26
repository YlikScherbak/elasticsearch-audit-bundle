<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Event;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * Dispatched (PSR-14) when a record could not be written, whatever the failure
 * policy. A place to count failures, alert, or queue the record for a retry of
 * your own.
 */
final class RecordFailedEvent
{
    public function __construct(
        public readonly AuditRecord $record,
        public readonly \Throwable $reason,
    ) {
    }
}
