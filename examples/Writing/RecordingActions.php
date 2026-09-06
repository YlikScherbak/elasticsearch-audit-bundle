<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Writing;

use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;

/**
 * Recording the things that are not entity changes: a call placed, a login refused,
 * a file shared with somebody outside the company.
 *
 * Inject `AuditWriter` anywhere and say what happened. An event is just a string —
 * `create`, `update` and `remove` are the three the bundle emits itself and have
 * constants; the rest is your application's vocabulary, and the history filters by
 * yours exactly as it does by those.
 */
final class RecordingActions
{
    public function __construct(private readonly AuditWriter $writer)
    {
    }

    /**
     * The plainest form: what it was about, and what happened to it.
     *
     * `attributes` are top-level fields of the document, which is what makes them
     * filterable — `AuditQuery::where('durationSeconds', …)`. A field nobody mapped
     * is still stored, it is simply not searchable, so an attribute you mean to
     * filter by wants a mapping: see the enricher example.
     */
    public function aCallWasPlaced(int $customerId, int $seconds): void
    {
        $this->writer->record('customer', $customerId, 'call.placed', attributes: ['durationSeconds' => $seconds]);
    }

    /**
     * With a diff. A `Change` is stored as `{"old": …, "new": …}`, which is what a
     * history screen needs to render one line without knowing the field.
     */
    public function aPriceWasCorrected(int $productId, int $from, int $to, string $because): void
    {
        $this->writer->record(
            'product',
            $productId,
            'price.corrected',
            changes: ['priceCents' => new Change($from, $to)],
            attributes: ['reason' => $because],
        );
    }

    /**
     * When the actor is not whoever is logged in. Nobody is authenticated during a
     * failed login, and the resolver would fall back to `system` — which would be
     * true and useless. Naming it here is what makes the record answer "who".
     *
     * Passing an actor is also what tells a frame not to merge this step with the
     * one before it: two hands on one object stay two records.
     */
    public function aLoginWasRefused(string $username, string $reason): void
    {
        $this->writer->record('user', $username, 'login.refused', attributes: ['reason' => $reason], actor: $username);
    }

    /**
     * The one record that must be readable before the response is sent — a share
     * whose confirmation screen shows the audit trail it just produced.
     *
     * `immediately: true` bypasses both the queue and any open frame, so use it for
     * the rare case that needs it rather than by habit: it makes the request wait
     * for Elasticsearch, which is what the transport exists to avoid.
     */
    public function aFileWasSharedAndTheScreenShowsItNow(int $fileId, string $with): void
    {
        $this->writer->write(
            new AuditRecord('file', $fileId, 'shared', attributes: ['sharedWith' => $with]),
            immediately: true,
        );
    }

    /**
     * A remove recorded by hand — for the objects the Doctrine listener never sees,
     * a row deleted by a native query among them.
     */
    public function aDraftWasDiscarded(int $draftId): void
    {
        $this->writer->record('draft', $draftId, AuditEvent::REMOVE);
    }
}
