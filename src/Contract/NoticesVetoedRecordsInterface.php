<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * A transport that wants to be told about the records it will never be handed.
 *
 * A listener on `RecordCreatedEvent` may stop a record from being written, and a veto
 * is a feature rather than a failure: nothing is logged and no transport is called, so
 * from the outside the record simply never existed. That is the right silence in
 * production and the wrong one for anything keeping account of what an operation did.
 *
 * The writer says so here, after the dispatch is over and the verdict is final. Asking
 * to be a listener instead cannot give the same answer: listeners run in priority order
 * and a veto set by one behind you is still a veto, so "last" is a race rather than a
 * guarantee — and one that an application wins simply by registering a listener of its
 * own at the same priority.
 *
 * Implemented by {@see \Borsche\ElasticsearchAuditBundle\Test\AuditCollector}, which is
 * what `transport: collector` puts at the end of the write path.
 */
interface NoticesVetoedRecordsInterface
{
    /**
     * Called once per record a listener stopped, with the record as it stood when the
     * veto became final — redacted, because the event redacts whatever a listener hands
     * back. Must not throw: nothing is waiting on it, and a transport that fails here
     * would turn a deliberate drop into a reported failure.
     */
    public function noticeVetoed(AuditRecord $record): void;
}
