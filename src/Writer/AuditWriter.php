<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The one entry point for writing history.
 *
 * Completes the record (timestamp, actor, id), lets the application's enrichers
 * add their attributes, routes it to an index and hands it to the transport. What
 * happens when that fails is decided by the FailurePolicy — by default the
 * failure is logged and swallowed, because an audit log that can take the
 * business operation down is worse than a gap in the history.
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
        try {
            $record = $this->complete($record);

            // Inside an open frame the record is held and merged with the others for
            // the same object; the frame writes the result when it closes. A remove or
            // a full buffer hands back records that have to go out right now.
            if (!$immediately && $this->frame !== null && $this->frame->accepts($record->objectType)) {
                foreach ($this->frame->hold($record) as $released) {
                    $this->dispatch($released, false);
                }

                return;
            }

            $this->dispatch($record, $immediately);
        } catch (\Throwable $e) {
            $this->reportFailure($e, $record);
        }
    }

    /**
     * Writes a record that already went through complete() — what a frame releases when
     * it closes. The completion pass is skipped on purpose: the timestamp, the actor, the
     * id and the enrichers' attributes were settled when the record entered the frame, and
     * running the enrichers again would repeat their queries and whatever else they do.
     */
    public function writeCompleted(AuditRecord $record): void
    {
        $this->dispatch($record, false);
    }

    /**
     * The completed record's way out: the RecordCreated event, the index, the transport.
     */
    private function dispatch(AuditRecord $record, bool $immediately): void
    {
        try {
            if ($this->events !== null) {
                $event = new RecordCreatedEvent($record);
                $this->events->dispatch($event);

                if ($event->isVetoed()) {
                    return;
                }

                $record = $event->getRecord();
            }

            $index = $this->indexResolver->resolve($record->objectType);
            $transport = $immediately ? $this->immediateTransport : $this->transport;

            $transport->send($index, $record->toDocument(), $record->id);
        } catch (\Throwable $e) {
            $this->reportFailure($e, $record);
        }
    }

    /**
     * Fills in what the caller left out and runs the enrichers.
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
     */
    public function reportFailure(\Throwable $e, ?AuditRecord $record = null): void
    {
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
