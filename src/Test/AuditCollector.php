<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Test;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\NoticesVetoedRecordsInterface;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;
use Borsche\ElasticsearchAuditBundle\Transport\WrittenAt;
use Psr\Clock\ClockInterface;

/**
 * The transport a test uses: it keeps the records instead of sending them.
 *
 * Turned on with `transport: collector`, which replaces the road to Elasticsearch and
 * nothing else — the records are completed, enriched, coalesced, redacted and routed
 * exactly as they would be, and the last step puts them here. That is the point: a
 * hand-written fake usually sits one layer higher and stops testing the layers it
 * replaced, and every one of those layers is where this bundle has found its defects.
 *
 *     borsche_elasticsearch_audit:
 *         transport: collector      # config/packages/test/…
 *
 *     $collector = static::getContainer()->get(AuditCollector::class);
 *
 *     $this->orders->pay($order);
 *
 *     self::assertCount(1, $collector->writtenFor('order', $order->getId()), $collector->explain());
 *
 * **It never reaches Elasticsearch.** Configured outside a test environment it is a
 * history that silently does not exist, so `audit:check` says so where an operator will
 * meet it.
 *
 * Two things a test will want that are not "what was written":
 *
 * - `vetoed()`, for a record a listener dropped on purpose. Those never reach a
 *   transport, so the writer tells this one about them through
 *   {@see NoticesVetoedRecordsInterface}, after the dispatch is over and the verdict is
 *   final. Listening for the event instead would be a race: a veto set by a listener
 *   behind you is still a veto;
 * - `held()`, for records an open frame is still holding. A test that coalesces and
 *   then asserts on `written()` before closing the frame finds nothing, and the honest
 *   answer is "they are held", not a helpful assertion that closes the frame itself —
 *   closing it is the behaviour under test.
 *
 * @see CollectedRecord for what comes back
 */
final class AuditCollector implements BatchTransportInterface, NoticesVetoedRecordsInterface
{
    /** @var list<CollectedRecord> */
    private array $written = [];

    /** @var list<CollectedRecord> */
    private array $vetoed = [];

    public function __construct(
        private readonly ?FrameBuffer $frame = null,
        private readonly ?ChangeRedactor $redactor = null,
        // The same clock the other transports stamp with, so what is collected is what
        // would have been stored rather than one field short of it.
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        $this->written[] = new CollectedRecord($index, WrittenAt::on($document, $this->clock), $id);
    }

    public function sendMany(array $items): BulkResult
    {
        foreach (WrittenAt::onEach($items, $this->clock) as $item) {
            $this->written[] = new CollectedRecord($item['index'], $item['document'], $item['id']);
        }

        return BulkResult::allSucceeded(\count($items));
    }

    /**
     * Records a veto, told by the writer once the verdict is final.
     *
     * @internal the writer calls this; a test reads vetoed()
     */
    public function noticeVetoed(AuditRecord $record): void
    {
        // No index and no writtenAt: routing happens after the event and no write was
        // attempted, so a record that was vetoed was never routed anywhere and never
        // written. Filling either in would be inventing the answer to a question nobody
        // asked.
        $this->vetoed[] = new CollectedRecord('', $record->toDocument(), $record->id);
    }

    /**
     * Everything that reached the transport, in the order it did.
     *
     * Empty when you expected records? Ask `held()`: a frame that is still open has
     * them, and they reach here when it closes.
     *
     * @return list<CollectedRecord>
     */
    public function written(): array
    {
        return $this->written;
    }

    /**
     * The written records about one object — the shape most assertions want.
     *
     * @return list<CollectedRecord>
     */
    public function writtenFor(string $objectType, int|string|null $objectId = null, ?string $event = null): array
    {
        return array_values(array_filter($this->written, static fn (CollectedRecord $record): bool => $record->isAbout($objectType, $objectId, $event)));
    }

    /**
     * Records a listener stopped from being written. Not failures: a veto is a feature,
     * and a test for one has nowhere else to look, because the record reaches no
     * transport and nothing is logged.
     *
     * @return list<CollectedRecord>
     */
    public function vetoed(): array
    {
        return $this->vetoed;
    }

    /**
     * How many records an open frame is holding. Zero when no frame is open.
     *
     * This is the answer to "why is written() empty", and it is a question rather than
     * an assertion on purpose: closing the frame to look would be the assertion changing
     * what it measures.
     */
    public function held(): int
    {
        return $this->frame?->isOpen() === true ? $this->frame->count() : 0;
    }

    /**
     * Whether a rule covers this field, asked of the configuration rather than guessed
     * from the value.
     *
     * A redacted field is stored as the placeholder, and a test comparing values meets
     * `***` with no way to tell "the rule worked" from "the application really wrote
     * three asterisks". This says which it is, and says it from the same code that did
     * the redacting.
     */
    public function redacts(string $objectType, string $field): bool
    {
        return $this->redactor?->redacts($objectType, $field) ?? false;
    }

    /**
     * What the collector holds, in words — for the message of a failing assertion,
     * which is the difference between "expected 1, got 0" and knowing why.
     */
    public function explain(): string
    {
        $said = [];

        $said[] = $this->written === []
            ? 'Nothing was written.'
            : sprintf('Written: %s.', implode(', ', array_map(static fn (CollectedRecord $r): string => $r->describe(), $this->written)));

        if ($this->vetoed !== []) {
            $said[] = sprintf('Vetoed by a listener: %s.', implode(', ', array_map(static fn (CollectedRecord $r): string => $r->describe(), $this->vetoed)));
        }

        if (($held = $this->held()) > 0) {
            $said[] = sprintf('%d record(s) are held by a frame that is still open, and reach the transport when it closes.', $held);
        }

        return implode(' ', $said);
    }

    /**
     * Forgets everything. Called by the test, and deliberately not by the kernel: a
     * kernel kept between requests resets its services on the next boot, and what this
     * one holds is the evidence the test came for rather than state that must not leak
     * between requests. A two-request test would have found it empty and been told
     * nothing was written.
     */
    public function reset(): void
    {
        $this->written = [];
        $this->vetoed = [];
    }
}
