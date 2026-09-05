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

    /**
     * @param (callable(AuditRecord): AuditRecord)|null $redact applied to whatever a listener
     *                                                          hands back, before the next one sees it
     */
    public function __construct(private AuditRecord $record, private $redact = null)
    {
    }

    public function getRecord(): AuditRecord
    {
        return $this->record;
    }

    /**
     * Replaces the record. What comes back is redacted immediately, not once the whole
     * dispatch is over, because the listener after this one reads it: one that reaches
     * for the entity again to add something would otherwise hand the value the redactor
     * had just removed to everyone behind it. The document was never at risk — the
     * writer redacts again before the transport — but between listeners is still
     * somewhere an application can see it.
     */
    public function setRecord(AuditRecord $record): void
    {
        $this->record = $this->redact === null ? $record : ($this->redact)($record);
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
