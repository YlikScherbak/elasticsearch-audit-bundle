<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing;

use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
use Borsche\ElasticsearchAuditBundle\Exception\FrameNestingException;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * "Everything that happens in here is one change."
 *
 * Open a frame around a business operation that saves several times on its way
 * to its result — a stock movement that reverses the old state in one flush and
 * applies the new one in the next — and the history gets one record per object
 * with the values before and after the whole operation, instead of one per save.
 *
 *   $frame->coalesce(fn () => $this->moveStock($order));
 *
 * Frames nest; the outermost one writes. Always pair begin() with end() in a
 * try/finally, or use coalesce(), which does that for you: the frame lives in a
 * service that a worker shares across messages, so an unclosed one would swallow
 * the next message's history too. FrameResetMiddleware is the safety net for that —
 * it releases (writes) what a leaked frame held, since those saves did go through.
 */
final class AuditFrame
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly FrameBuffer $buffer,
        private readonly AuditWriter $writer,
        ?LoggerInterface $logger = null,
        private readonly ?OutboxContext $outbox = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Opens a frame. `atomic: true` asks for one more thing than coalescing: that
     * nothing of this operation leaves before it closes.
     *
     * Ordinarily a frame publishes some records early - a remove is terminal, a step by
     * another actor ends the record before it, and max_held opens the valve. That is
     * fine for a trail and fatal for a caller holding a database transaction open: what
     * left cannot be taken back when the transaction rolls back. An atomic frame keeps
     * all of it, and refuses the operation rather than releasing early.
     *
     * It must be the outermost frame: what the buffer holds belongs to every level at
     * once, so a nested level cannot promise something about records that are not only
     * its own.
     */
    public function begin(bool $atomic = false): void
    {
        $this->buffer->open($atomic);
    }

    /**
     * Closes the frame; the outermost end() writes what was collected. With
     * on_failure: throw a failed write surfaces from here, not from the flush that
     * produced the record.
     */
    public function end(): void
    {
        try {
            $this->writer->writeManyCompleted($this->buffer->close() ?? []);
        } catch (\Throwable $write) {
            // Drained even here, or a comparator failure kept for the writer would sit
            // in the buffer and surface inside the NEXT operation, as a failure event
            // about a record that operation never wrote. The write's own exception is
            // the one that surfaces: it is what actually went wrong.
            $this->reportFinalizeFailuresQuietly();

            throw $write;
        }

        $this->reportFinalizeFailures();
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     * @param bool          $atomic    nothing of this operation leaves the frame before it
     *                                 closes; see begin()
     *
     * @return T
     */
    public function coalesce(callable $operation, bool $atomic = false): mixed
    {
        $this->begin($atomic);

        try {
            $result = $operation();
        } catch (FrameOverflowException $refused) {
            // The one exception that is not a failed operation but a refused one.
            // on_overflow: throw says the coalescing guarantee — one record per object
            // for this operation — cannot be kept, so the operation is abandoned; and an
            // operation that was abandoned has no history. Writing what the frame held
            // would be what "release" already does, only with an exception on top: the
            // trail is fragmented either way, and a setting that gives no different
            // guarantee has no reason to exist.
            //
            // The buffer refused itself where the overflow was raised, so there is
            // nothing to drop here and nothing this frame could still publish. This
            // level is closed like any other — not reset: a reset would take every
            // enclosing frame down with it, and a caller that catches this exception
            // inside an outer frame would go on recording outside a frame it believes
            // is still open.
            //
            // What this cannot undo is the database. Those records reached the frame
            // because their saves committed, so the caller's own transaction is what
            // rolls them back — which is why this setting is only meaningful where
            // there is one.
            $this->say('warning', 'An operation was refused because the audit frame could not hold it (coalescing.on_overflow: throw); the {held} record(s) it had collected were dropped, and nothing it records from here until the outermost frame closes will be written either. The database changes behind them are not undone by this — the transaction around the operation is what rolls those back.', ['held' => $refused->held()]);

            $this->end();

            throw $refused;
        } catch (\Throwable $failed) {
            // The frame closes and writes what it held either way — those saves went
            // through. But when that write fails too, it must not stand in place of
            // the reason the operation died: the caller's error handling keys off the
            // cause (a plain finally would surface the close's exception instead, the
            // original demoted to its previous).
            try {
                $this->end();
            } catch (\Throwable $close) {
                $this->say('error', 'The audit frame could not close cleanly after the operation had already failed: {reason}. The operation\'s own exception follows.', ['reason' => $close->getMessage(), 'exception' => $close]);
            }

            throw $failed;
        }

        $this->end();

        return $result;
    }

    /**
     * Closes a frame somebody left open and writes what it held — the leak path, and
     * what FrameResetMiddleware calls after every message. The records are written,
     * not dropped: they come from saves that went through, and history that happened
     * belongs in the log. Logged as a warning, because a frame that needed releasing
     * is a missing try/finally somewhere.
     *
     * @return bool whether there was anything to release
     */
    public function release(): bool
    {
        $held = $this->buffer->count();
        $records = $this->buffer->closeAll();

        if ($records === null) {
            return false;
        }

        $this->say('warning', 'An audit frame was left open; its {held} held record(s) are being written and the frame closed. Pair begin() with end() in a try/finally, or use coalesce().', ['held' => $held]);

        try {
            $this->writer->writeManyCompleted($records);
        } catch (\Throwable $write) {
            // The same drain end() does, and for the same reason: a comparator failure
            // left in the buffer surfaces inside the NEXT operation, as a failure event
            // about a record that operation never wrote.
            $this->reportFinalizeFailuresQuietly();

            throw $write;
        }

        $this->reportFinalizeFailures();

        return true;
    }

    /**
     * Drops an unclosed frame and everything it held, writing nothing. For the case
     * where the records must not exist — a dry run, an operation whose saves were
     * rolled back by hand. Prefer release() when in doubt: a gap in the history is
     * harder to notice than a record too many.
     *
     * @return bool whether there was anything to drop
     */
    public function reset(): bool
    {
        // Not from inside somebody else's frame. What the buffer holds belongs to every
        // level at once - the records of one object are merged whoever recorded them -
        // so a nested reset() dropped the enclosing operation's history too, set the
        // depth to zero and closed its frame. What that operation recorded afterwards
        // then went to the index unmerged, out of a frame it believed was still open,
        // and its own held records were simply gone. All of that silently.
        if ($this->buffer->isNested()) {
            throw FrameNestingException::cannotResetFromInside();
        }

        // Inside an audit transaction this is a decision the transaction has to hear
        // about: dropping the history on purpose and then committing the change is the
        // one outcome that arrangement exists to prevent, and it would otherwise be
        // completely silent — the frame ends up empty either way.
        $this->outbox?->spoil('the audit frame was reset inside the transaction, so the operation has no history');

        return $this->drop('An audit frame was left open and has been reset; {held} held record(s) were dropped. Pair begin() with end() in a try/finally, or use coalesce().');
    }

    /**
     * Every level of the frame gone, whatever depth it was left at, and nothing
     * written.
     *
     * What reset() does for a caller, without the two things a caller needs: the
     * refusal to reach into somebody else's frame — there is nobody else here, the
     * transaction owns the outermost level — and the mark on the context, since the
     * transaction is already rolling back and knows why.
     *
     * @internal AuditTransaction's own cleanup
     */
    public function dropEverything(): bool
    {
        return $this->drop('An audit transaction was rolled back; the {held} record(s) its frame held were dropped with it.');
    }

    /**
     * Drops the frame and says why in the caller's own words: "left open" and "refused
     * because it overflowed" are different events, and a log line naming the wrong one
     * sends whoever reads it looking for a missing try/finally that is not there.
     */
    /**
     * Says it, and does not let saying it become the failure.
     *
     * Everything this frame logs is about something that already went wrong or is
     * already being undone, and the logger is the application's code like the rest of
     * it. One that throws used to reach the caller in place of the reason their
     * operation failed — and, worse, from release(), before the records it was
     * announcing had been written at all.
     *
     * The same guard AuditTransaction::report() and AuditWriter::say() put around their
     * own logging.
     *
     * @param array<string, mixed> $context
     */
    private function say(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
            // Nowhere left to say it.
        }
    }

    private function drop(string $why): bool
    {
        $held = $this->buffer->count();

        if (!$this->buffer->reset()) {
            return false;
        }

        $this->say('warning', $why, ['held' => $held]);

        return true;
    }

    public function isOpen(): bool
    {
        return $this->buffer->isOpen();
    }

    /**
     * The same as reportFinalizeFailures(), when something more important is already on
     * its way out: the buffer is
     * drained so nothing leaks into the next operation, and whatever the reporting
     * itself raises is logged rather than replacing the exception in flight.
     */
    private function reportFinalizeFailuresQuietly(): void
    {
        try {
            $this->reportFinalizeFailures();
        } catch (\Throwable $e) {
            $this->say('error', 'A comparator failure could not be reported while the frame was closing: {reason}.', ['reason' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * A comparator that threw while the frame closed: its record went out unfinalized,
     * so nothing is lost — and the mistake travels the failure policy like the same
     * mistake on the hold() path, after every record was written. With "throw" the
     * first one raises; the others were reported before it did.
     */
    private function reportFinalizeFailures(): void
    {
        $thrown = null;

        foreach ($this->buffer->takeFinalizeFailures() as [$record, $e]) {
            try {
                $this->writer->reportFailure($e, $record);
            } catch (\Throwable $raised) {
                $thrown ??= $raised;
            }
        }

        if ($thrown !== null) {
            throw $thrown;
        }
    }
}
