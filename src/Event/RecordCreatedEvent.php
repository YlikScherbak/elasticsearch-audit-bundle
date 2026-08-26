<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Event;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * Dispatched (PSR-14) after a record is complete and enriched, right before it is
 * sent. Listeners may replace the record or veto it altogether — to drop noise
 * (a heartbeat field), to redact a value, to mirror the record somewhere else.
 */
final class RecordCreatedEvent
{
    private bool $vetoed = false;

    public function __construct(private AuditRecord $record)
    {
    }

    public function getRecord(): AuditRecord
    {
        return $this->record;
    }

    public function setRecord(AuditRecord $record): void
    {
        $this->record = $record;
    }

    /**
     * Stops the record from being written. Not an error: nothing is logged.
     */
    public function veto(): void
    {
        $this->vetoed = true;
    }

    public function isVetoed(): bool
    {
        return $this->vetoed;
    }
}
