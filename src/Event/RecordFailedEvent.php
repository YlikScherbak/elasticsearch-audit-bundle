<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Event;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * Dispatched (PSR-14) when a record could not be handed over, whatever the failure
 * policy. A place to count failures, alert, or queue the record for a retry of
 * your own.
 *
 * "Handed over" is the precise word, and it matters with the messenger transport:
 * there, a successful hand-over means the message was dispatched, and the write to
 * Elasticsearch happens later in a worker. A failure there is a failed message —
 * Messenger retries it, and eventually routes it to the failure transport — and no
 * event of this kind is dispatched for it, because the process that dispatched the
 * record is long gone. Monitoring an asynchronous setup means watching the failure
 * transport as well as this event; with the synchronous transport the two coincide.
 */
final class RecordFailedEvent
{
    public function __construct(
        public readonly AuditRecord $record,
        public readonly \Throwable $reason,
    ) {
    }
}
