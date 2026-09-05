<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
use Borsche\ElasticsearchAuditBundle\Model\AuditOrigin;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;

/**
 * The state behind an open frame: records held back, one per object, merged as
 * more arrive for the same object.
 *
 * Merging keeps the earliest old and the latest new of every field, so a stock
 * line that went 1000 → 1040 → 995 across three flushes is recorded once as
 * 1000 → 995, and one that came back to 1000 is not recorded at all — the noise
 * a business operation makes on its way to its result is not history.
 *
 * A field is only noise if it actually moved somewhere and came back. A field
 * whose two sides were the same in every step never moved: that is a context
 * field (what #[Auditable(alwaysRecord: ...)] produces), and it stays, so a
 * coalesced record reads like the ones written outside a frame. What decides
 * whether anything is worth recording is therefore movement, not emptiness.
 *
 * Frames nest: only the outermost close() releases the records. A remove is
 * terminal: whatever was held for that object goes out first, then the remove.
 *
 * Pure state and rules, no I/O — the writer holds records here while a frame is
 * open, AuditFrame opens and closes it, and both send what comes back.
 *
 * @internal the state behind AuditFrame; open and close frames through AuditFrame, not here
 */
final class FrameBuffer
{
    private int $depth = 0;

    /** @var array<string, AuditRecord> objectType|objectId => merged record, in first-seen order */
    private array $held = [];

    /** @var array<string, array<string, true>> held key => fields whose sides differed in some step */
    private array $moved = [];

    /** @var list<array{AuditRecord, \Throwable}> comparator failures while closing, awaiting the writer's failure policy */
    private array $finalizeFailures = [];

    /**
     * @param list<string> $objectTypes object types to coalesce; [] means every type
     * @param int          $maxHeld     safety valve: past this many objects the buffer releases what it has
     * @param bool         $enabled     false: frames still open and close, but hold nothing
     * @param bool         $throwOnOverflow  refuse the operation instead of releasing early
     */
    public function __construct(
        private readonly ValueComparatorInterface $comparator = new ValueComparator(),
        private readonly array $objectTypes = [],
        private readonly int $maxHeld = 10_000,
        private readonly bool $enabled = true,
        private readonly bool $throwOnOverflow = false,
    ) {
        if ($maxHeld < 1) {
            throw new \InvalidArgumentException('max_held must be at least 1.');
        }
    }

    public function open(): void
    {
        ++$this->depth;
    }

    /**
     * Closes one level. Returns the records to write when the outermost frame
     * closed, null while inner frames are still open. Closing a frame that was
     * never opened is ignored: a misplaced end() must not break anything.
     *
     * @return list<AuditRecord>|null
     */
    public function close(): ?array
    {
        if ($this->depth === 0) {
            return null;
        }

        if (--$this->depth > 0) {
            return null;
        }

        return $this->release();
    }

    /**
     * Drops every open frame and everything held, writing nothing: the frame did not
     * close the normal way, so nothing says the changes it saw reached the database.
     *
     * @return bool whether there was anything to drop
     */
    public function reset(): bool
    {
        $hadState = $this->depth > 0 || $this->held !== [];
        $this->depth = 0;
        $this->held = [];
        $this->moved = [];
        // Including these: a comparator failure kept for the writer to report belongs
        // to the operation being dropped, and one left behind surfaced in the middle of
        // the next one — a failure event about a record nobody in that operation wrote.
        $this->finalizeFailures = [];

        return $hadState;
    }

    /**
     * Closes every open level at once and hands back what was held — the leak path.
     * Unlike reset() nothing is thrown away: what the buffer holds came from saves
     * that went through, and history that happened should be recorded.
     *
     * @return list<AuditRecord>|null null when no frame was open and nothing was held
     */
    public function closeAll(): ?array
    {
        if ($this->depth === 0 && $this->held === []) {
            return null;
        }

        $this->depth = 0;

        return $this->release();
    }

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    public function count(): int
    {
        return \count($this->held);
    }

    /**
     * Whether records of this type are held while a frame is open.
     */
    public function accepts(string $objectType): bool
    {
        return $this->enabled && $this->isOpen() && ($this->objectTypes === [] || \in_array($objectType, $this->objectTypes, true));
    }

    /**
     * Takes a record in. Returns whatever has to be written right away — normally
     * nothing; on a remove, the held record for that object followed by the remove
     * itself; when the buffer is full, everything held so far.
     *
     * @return list<AuditRecord>
     */
    public function hold(AuditRecord $record): array
    {
        // Escaped, because both halves are free-form strings through
        // AuditWriter::record(): unescaped, an object type "a|b" with id "c" and a type
        // "a" with id "b|c" are one key, and two objects' histories would merge inside
        // a frame. The same reason composite identifiers escape their own delimiter.
        $key = self::identity($record);

        if ($record->event === AuditEvent::REMOVE) {
            $held = $this->held[$key] ?? null;
            $moved = $this->moved[$key] ?? [];
            unset($this->held[$key], $this->moved[$key]);

            // Through the same net release() uses: a comparator that throws here used
            // to escape past the failure policy, and because the held record had
            // already been taken out of the buffer, the update AND the remove were
            // gone — under a policy meant to keep the operation running.
            $out = $held === null ? [] : array_values(array_filter([$this->finalizeSafely($held, $moved)]));
            $out[] = $record;

            return $out;
        }

        if (!isset($this->held[$key])) {
            // Releasing keeps every record and gives up the promise: an object let go
            // early can produce a second record for an operation whose net effect was
            // nothing. A deployment that reads the trail for that promise says so.
            if (\count($this->held) >= $this->maxHeld && $this->throwOnOverflow) {
                throw FrameOverflowException::past($this->maxHeld);
            }

            $out = \count($this->held) >= $this->maxHeld ? $this->release() : [];
            $this->held[$key] = $record;
            $this->markMoved($key, $record);

            return $out;
        }

        $held = $this->held[$key];

        // Two different hands on one object inside one operation. Coalescing folds the
        // steps of an operation into the record that describes it, and it can do that
        // because the steps are one person's — merging across actors would put the
        // second one's change under the first one's name, and "who did this" is the
        // question a history is kept to answer. It happens on purpose more often than
        // by accident: a step recorded with an explicit `actor:` (a system correction
        // beside a user's edit) is exactly this. Neither record is dropped and neither
        // is misattributed — the held one goes out as it stands, and this one starts
        // the next.
        if ($held->actor !== $record->actor) {
            $moved = $this->moved[$key] ?? [];
            unset($this->moved[$key]);

            $this->held[$key] = $record;
            $this->markMoved($key, $record);

            return array_values(array_filter([$this->finalizeSafely($held, $moved)]));
        }

        $this->held[$key] = self::merge($held, $record);
        $this->markMoved($key, $record);

        return [];
    }

    /**
     * Which object a record is about, as one string that cannot be two objects.
     */
    private static function identity(AuditRecord $record): string
    {
        $escape = static fn (string $part): string => str_replace(['\\', '|'], ['\\\\', '\\|'], $part);

        return $escape($record->objectType).'|'.$escape((string) $record->objectId);
    }

    /**
     * @return list<AuditRecord>
     */
    private function release(): array
    {
        $held = $this->held;
        $moved = $this->moved;
        $this->held = [];
        $this->moved = [];

        $released = [];

        foreach ($held as $key => $record) {
            $final = $this->finalizeSafely($record, $moved[$key] ?? []);

            if ($final !== null) {
                $released[] = $final;
            }
        }

        return $released;
    }

    /**
     * Comparator exceptions collected while a frame closed, for the caller that owns a
     * writer to report; reading them empties the list.
     *
     * @return list<array{AuditRecord, \Throwable}>
     */
    public function takeFinalizeFailures(): array
    {
        $failures = $this->finalizeFailures;
        $this->finalizeFailures = [];

        return $failures;
    }

    /**
     * Remembers which fields this step actually moved. "Moved" is the plain question
     * of whether the two sides differ at all — the application's comparator gets its
     * say later, on the merged pair, about whether the record is worth writing.
     */
    private function markMoved(string $key, AuditRecord $record): void
    {
        foreach ($record->changes as $field => $change) {
            $pair = self::asChange($change);

            if ($pair !== null && !ValueComparator::same($pair->old, $pair->new)) {
                $this->moved[$key][$field] = true;
            }
        }
    }

    /**
     * Earliest old, latest new; the event and identity of the first record — a
     * create followed by updates is still one create, with the final values.
     *
     * "Identity" includes the actor, which is why hold() never brings two actors here:
     * the merged record keeps the first one's name, and that is only honest when both
     * steps are the same one's.
     */
    private static function merge(AuditRecord $first, AuditRecord $next): AuditRecord
    {
        $changes = $first->changes;

        foreach ($next->changes as $field => $change) {
            $existing = $changes[$field] ?? null;

            if (self::asChange($change) === null) {
                $changes[$field] = $change; // free-form data: the latest value stands
                continue;
            }

            $incoming = self::asChange($change);
            $previous = $existing === null ? null : self::asChange($existing);

            $changes[$field] = $previous === null
                ? $incoming
                : new Change($previous->old, $incoming->new);
        }

        // One merged record cannot honestly claim a single origin when the steps came
        // from different places: a listener's insert followed by the application's own
        // correction is both, and saying "doctrine" would be a lie an enricher acts on.
        $origin = $first->origin === $next->origin ? $first->origin : AuditOrigin::Mixed;

        return $first
            ->withChanges($changes)
            ->withAttributes($next->attributes)
            ->withOrigin($origin);
    }

    /**
     * finalize(), with the net every caller needs: the application's comparator runs
     * here, and an exception from it must never be what loses a record. The record
     * goes out unfinalized instead — noisier history, never missing history — and the
     * mistake is kept for the writer to report through the failure policy, exactly as
     * the same mistake is reported on the hold() path.
     *
     * @param array<string, true> $moved
     */
    private function finalizeSafely(AuditRecord $record, array $moved): ?AuditRecord
    {
        try {
            return $this->finalize($record, $moved);
        } catch (\Throwable $e) {
            $this->finalizeFailures[] = [$record, $e];

            return $record;
        }
    }

    /**
     * Drops the fields that moved and came back, keeps the ones that never moved as
     * context, and refuses an update in which nothing moved at all.
     *
     * @param array<string, true> $moved fields whose sides differed in some step
     */
    private function finalize(AuditRecord $record, array $moved): ?AuditRecord
    {
        $changes = [];
        $anythingChanged = false;

        foreach ($record->changes as $field => $change) {
            $pair = self::asChange($change);

            if ($pair === null) {
                $changes[$field] = $change; // free-form data: content in its own right
                $anythingChanged = true;
                continue;
            }

            // The interface answers ?bool, and null is "no opinion", not "never
            // differed": the fallback is the same one every consumer of a comparator
            // applies (see ChangeSetBuilder), so one injected link behaves like the
            // full chain would.
            if ($this->comparator->equals($record->objectType, $field, $pair->old, $pair->new) ?? ValueComparator::same($pair->old, $pair->new)) {
                if (isset($moved[$field])) {
                    continue; // went somewhere and came back: the noise coalescing exists to remove
                }

                $changes[$field] = $change; // never differed: the record's context

                continue;
            }

            $changes[$field] = $change;
            $anythingChanged = true;
        }

        if ($record->event === AuditEvent::UPDATE && !$anythingChanged) {
            return null;
        }

        return $record->withChanges($changes);
    }

    private static function asChange(mixed $value): ?Change
    {
        if ($value instanceof Change) {
            return $value;
        }

        return Change::isPair($value) ? new Change($value['old'], $value['new']) : null;
    }
}
