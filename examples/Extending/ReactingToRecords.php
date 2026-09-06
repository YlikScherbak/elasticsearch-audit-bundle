<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Extending;

use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The two PSR-14 events, and what each one is for.
 *
 * `RecordCreatedEvent` is the last place the application can change its mind about
 * a record: replace it, or drop it. It fires after the enrichers and after a frame
 * has merged its steps, so what a listener sees is what would be stored.
 *
 * `RecordFailedEvent` fires when a record could not be handed over. Note the word:
 * with the messenger transport a successful hand-over means the *message* was
 * dispatched, and the write happens later in a worker — a failure there is a failed
 * message that Messenger retries and finally routes to its failure transport, with
 * no event of this kind at all. Monitoring an asynchronous setup means watching
 * both; with the synchronous transport the two coincide.
 */
final class ReactingToRecords
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Dropping noise. A veto is not an error and nothing is logged — this is how a
     * heartbeat field or a machine's own bookkeeping stays out of a trail people
     * read.
     *
     * Conditional rules live here rather than in the configuration: redact only for
     * this tenant, keep nothing outside office hours, drop what a particular
     * integration writes.
     */
    #[AsEventListener]
    public function dropWhatNobodyWantsToRead(RecordCreatedEvent $event): void
    {
        $record = $event->getRecord();

        if ($record->objectType === 'heartbeat') {
            $event->veto();

            return;
        }

        // An update whose only change is a field the domain does not care about.
        if ($record->changes !== [] && array_keys($record->changes) === ['lastSeenAt']) {
            $event->veto();
        }
    }

    /**
     * Replacing the record. What a listener hands back is redacted again before the
     * next listener sees it and again before the transport, so a listener that
     * reaches for the entity a second time cannot undo the privacy policy by
     * accident.
     *
     * Keep the id the record came with — `with*()` methods do. A replacement with a
     * new id would be stored a second time by a retry instead of overwriting
     * itself, which is the one way to turn a retried write into a duplicate.
     */
    #[AsEventListener]
    public function addTheRequestItCameFrom(RecordCreatedEvent $event): void
    {
        $record = $event->getRecord();

        if ($record->objectType !== 'order') {
            return;
        }

        $event->setRecord($record->withAttributes(['channel' => 'web']));
    }

    /**
     * Counting failures. Under `on_failure: log` — the default — a failed write
     * never reaches the business operation, which is the point: the audit log
     * cannot take an order down. That also means nobody notices unless something
     * here is watching.
     *
     * The reason is an exception like any other; what it may quote from the cluster
     * is governed by `redact.failure_details`, and the original is reachable
     * through `getPrevious()` when the setting allows it.
     */
    #[AsEventListener]
    public function countAndAlert(RecordFailedEvent $event): void
    {
        $this->logger->error('An audit record was not written: {type} #{id} ({event}).', [
            'type' => $event->record->objectType,
            'id' => (string) $event->record->objectId,
            'event' => $event->record->event,
            'exception' => $event->reason,
        ]);

        // …and increment a counter your alerting can see. A trail that quietly
        // stopped being written is worse than one that never existed.
    }
}
