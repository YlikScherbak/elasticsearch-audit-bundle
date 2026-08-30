<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MergedRecordEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The one entry point for writing history.
 *
 * Completes the record (timestamp, actor, id), lets the application's enrichers
 * add their attributes, redacts what must not be stored, routes it to an index and
 * hands it to the transport. What happens when that fails is decided by the
 * FailurePolicy — by default the failure is logged and swallowed, because an audit
 * log that can take the business operation down is worse than a gap in the history.
 *
 * record() and write() take one record, writeAll() a batch that goes out in one
 * request. The rest of the public methods are seams for the bundle's own listener
 * and frame, and are marked as such.
 */
final class AuditWriter
{
    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly TransportInterface $immediateTransport,
        private readonly IndexResolver $indexResolver,
        private readonly ActorResolverInterface $actorResolver,
        private readonly ClockInterface $clock,
        private readonly iterable $enrichers = [],
        private readonly FailurePolicy $failurePolicy = FailurePolicy::Log,
        ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?FrameBuffer $frame = null,
        private readonly ?ChangeRedactor $redactor = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    private readonly LoggerInterface $logger;

    /**
     * Records a domain action that is not a Doctrine change: a call made, a login
     * failed, a file shared. $changes may hold Change objects or any JSON-able data
     * you want to show alongside the event.
     *
     * @param array<string, Change|mixed> $changes
     * @param array<string, mixed>        $attributes
     */
    public function record(
        string $objectType,
        int|string $objectId,
        string $event,
        array $changes = [],
        array $attributes = [],
        ?\DateTimeImmutable $at = null,
        ?string $actor = null,
    ): void {
        $this->write(new AuditRecord($objectType, $objectId, $event, $at, $actor, $changes, $attributes));
    }

    /**
     * Writes a fully built record.
     *
     * @param bool $immediately bypass the configured transport and write synchronously —
     *                          for the rare record that must be visible before the request ends
     */
    public function write(AuditRecord $record, bool $immediately = false): void
    {
        $released = null;

        // Building the record is this record's business, so a failure here is reported
        // against it. Delivering is each released record's own business, and reported
        // against that one — which is also why delivery happens outside this try: two
        // catch blocks around one failure reported it twice and buried the cause.
        try {
            $record = $this->complete($record);

            // Inside an open frame the record is held and merged with the others for
            // the same object; the frame writes the result when it closes. A remove or
            // a full buffer hands back records that have to go out right now.
            if (!$immediately && $this->frame !== null && $this->frame->accepts($record->objectType)) {
                $released = iterator_to_array($this->frame->hold($record), false);
            }
        } catch (\Throwable $e) {
            $this->reportFailure($e, $record);

            return;
        }

        if ($released !== null) {
            foreach ($released as $one) {
                $this->deliver($one, false);
            }

            return;
        }

        $this->deliver($record, $immediately);
    }

    /**
     * Writes a record that already went through complete() — what a frame releases when
     * it closes. The completion pass is skipped on purpose: the timestamp, the actor, the
     * id and the enrichers' attributes were settled when the record entered the frame, and
     * running the enrichers again would repeat their queries and whatever else they do.
     *
     * @internal the seam AuditFrame writes through; use write() or writeAll(), which complete a record first
     */
    public function writeCompleted(AuditRecord $record): void
    {
        $this->deliver($record, false);
    }

    /**
     * Writes several records as one batch — what a flush or a closing frame produces.
     * Each record is completed and held or released by the frame exactly as write()
     * would; what comes out goes to the transport in one call when it can take a
     * batch, and one by one otherwise. Failures are reported per record.
     *
     * @param list<AuditRecord> $records
     */
    public function writeAll(array $records): void
    {
        $outgoing = [];

        foreach ($records as $record) {
            try {
                $record = $this->complete($record);

                if ($this->frame !== null && $this->frame->accepts($record->objectType)) {
                    foreach ($this->frame->hold($record) as $released) {
                        $outgoing[] = $released;
                    }

                    continue;
                }

                $outgoing[] = $record;
            } catch (\Throwable $e) {
                $this->reportFailure($e, $record);
            }
        }

        $this->writeManyCompleted($outgoing);
    }

    /**
     * The batch form of writeCompleted(): records that already went through the
     * completion pass, sent together.
     *
     * @internal the seam AuditFrame writes through; use writeAll().
     *
     * @param list<AuditRecord> $records
     */
    public function writeManyCompleted(array $records): void
    {
        if ($records === []) {
            return;
        }

        if (!$this->transport instanceof BatchTransportInterface) {
            foreach ($records as $record) {
                $this->deliver($record, false);
            }

            return;
        }

        $items = [];
        $sent = [];

        foreach ($records as $record) {
            try {
                $prepared = $this->prepare($record);

                if ($prepared === null) {
                    continue; // vetoed
                }

                $items[] = ['index' => $this->indexResolver->resolve($prepared->objectType), 'document' => $prepared->toDocument(), 'id' => $prepared->id];
                $sent[] = $prepared;
            } catch (\Throwable $e) {
                $this->reportFailure($e, $record);
            }
        }

        if ($items === []) {
            return;
        }

        try {
            $result = $this->transport->sendMany($items);
        } catch (\Throwable $e) {
            // The whole batch did not go: every record failed, and with "throw" the
            // exception carries the first of them — the others are still logged.
            $this->reportFailures($sent, $e);

            return;
        }

        $failures = [];

        foreach ($result->failures as $position => $failure) {
            $failures[] = [$sent[$position], RequestRejectedException::because($failure['status'], $failure['reason'], new \RuntimeException('bulk item rejected'))];
        }

        $this->reportEach($failures);
    }

    /**
     * One record, one failure: whatever goes wrong on its way out is reported once,
     * against the record it happened to.
     */
    private function deliver(AuditRecord $record, bool $immediately): void
    {
        try {
            $this->dispatch($record, $immediately);
        } catch (\Throwable $e) {
            $this->reportFailure($e, $record);
        }
    }

    /**
     * Sends the record, or nothing when a listener vetoed it. It does not catch: the
     * failure policy belongs to the callers above, and applying it here as well turned
     * one transport error into two RecordFailedEvents and an exception whose cause was
     * another exception of the same kind.
     */
    private function dispatch(AuditRecord $record, bool $immediately): void
    {
        $prepared = $this->prepare($record);

        if ($prepared === null) {
            return; // vetoed
        }

        $index = $this->indexResolver->resolve($prepared->objectType);
        $transport = $immediately ? $this->immediateTransport : $this->transport;

        $transport->send($index, $prepared->toDocument(), $prepared->id);
    }

    /**
     * The last steps before a record leaves: redaction, then the RecordCreated event.
     * Null when a listener vetoed it.
     */
    private function prepare(AuditRecord $record): ?AuditRecord
    {
        // Whatever a frame merged is what these see, and they run before redaction so
        // that what they add is redacted like the rest.
        foreach ($this->enrichers as $enricher) {
            if ($enricher instanceof MergedRecordEnricherInterface && $enricher->supports($record)) {
                $record = $enricher->enrich($record);
            }
        }

        // Redaction happens here, on the way out, and not in complete(): a frame has to
        // see the real values to know that a field moved, and the event below is the
        // first place a record is seen outside the writer.
        $record = $this->redactor?->redact($record) ?? $record;

        if ($this->events === null) {
            return $record;
        }

        $event = new RecordCreatedEvent($record);
        $this->events->dispatch($event);

        if ($event->isVetoed()) {
            return null;
        }

        // Redacted once more, and this is not belt and braces: a listener may replace the
        // record wholesale, and one that reaches for the entity again to add something
        // would hand back the value the first pass removed. The redactor is the last word
        // before the transport, or it is not a policy.
        return $this->redactor?->redact($event->getRecord()) ?? $event->getRecord();
    }

    /**
     * Reports one failure for each record; with "throw", the first exception is raised
     * after every record was logged and every RecordFailedEvent dispatched, so a batch
     * that failed as a whole does not lose the other records' failure notices.
     *
     * @param list<AuditRecord> $records
     */
    private function reportFailures(array $records, \Throwable $e): void
    {
        $this->reportEach(array_map(static fn (AuditRecord $r) => [$r, $e], $records));
    }

    /**
     * @param list<array{AuditRecord, \Throwable}> $failures
     */
    private function reportEach(array $failures): void
    {
        $first = null;

        foreach ($failures as [$record, $e]) {
            try {
                $this->reportFailure($e, $record);
            } catch (WriteFailedException $thrown) {
                $first ??= $thrown;
            }
        }

        if ($first !== null) {
            throw $first;
        }
    }

    /**
     * Fills in what the caller left out and runs the enrichers.
     *
     * @internal write() and writeAll() complete a record on the way through
     */
    public function complete(AuditRecord $record): AuditRecord
    {
        if ($record->loggedAt === null) {
            $record = $record->withLoggedAt(\DateTimeImmutable::createFromInterface($this->clock->now()));
        }

        if ($record->actor === null) {
            $record = $record->withActor($this->actorResolver->resolve());
        }

        if ($record->id === null) {
            $record = $record->withId(RecordId::v7($record->loggedAt ?? throw new \LogicException('unreachable: the timestamp was just set')));
        }

        foreach ($this->enrichers as $enricher) {
            // The merged ones wait for prepare(): what they say is about the record that
            // will be stored, not about the step that is being recorded right now.
            if ($enricher instanceof MergedRecordEnricherInterface) {
                continue;
            }

            if ($enricher->supports($record)) {
                $record = $enricher->enrich($record);
            }
        }

        return $record;
    }

    /**
     * Applies the failure policy to something that kept a record from being written —
     * a transport error, or (for the Doctrine listener) a record that could not even be
     * built, in which case there is no record to report. Log: logged and done. Throw:
     * WriteFailedException.
     *
     * @throws WriteFailedException
     *
     * @internal the seam AuditSubscriber reports through when it cannot even build a record
     */
    public function reportFailure(\Throwable $e, ?AuditRecord $record = null): void
    {
        // The record may have failed before it was redacted; nothing carrying it out of
        // here — the event, the exception — may hold a value that must not be stored.
        $record = $record === null ? null : ($this->redactor?->redact($record) ?? $record);

        if ($record !== null) {
            $this->events?->dispatch(new RecordFailedEvent($record, $e));
        }

        if ($this->failurePolicy === FailurePolicy::Throw) {
            throw WriteFailedException::for($record, $e);
        }

        $this->logger->error('Audit record could not be written: {reason}', [
            'reason' => $e->getMessage(),
            'objectType' => $record?->objectType,
            'objectId' => $record?->objectId,
            'event' => $record?->event,
            'exception' => $e,
        ]);
    }
}
