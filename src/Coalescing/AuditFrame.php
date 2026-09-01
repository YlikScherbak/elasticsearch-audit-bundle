<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing;

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
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function begin(): void
    {
        $this->buffer->open();
    }

    /**
     * Closes the frame; the outermost end() writes what was collected. With
     * on_failure: throw a failed write surfaces from here, not from the flush that
     * produced the record.
     */
    public function end(): void
    {
        $this->writer->writeManyCompleted($this->buffer->close() ?? []);
        $this->reportFinalizeFailures();
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function coalesce(callable $operation): mixed
    {
        $this->begin();

        try {
            $result = $operation();
        } catch (\Throwable $failed) {
            // The frame closes and writes what it held either way — those saves went
            // through. But when that write fails too, it must not stand in place of
            // the reason the operation died: the caller's error handling keys off the
            // cause (a plain finally would surface the close's exception instead, the
            // original demoted to its previous).
            try {
                $this->end();
            } catch (\Throwable $close) {
                $this->logger->error('The audit frame could not close cleanly after the operation had already failed: {reason}. The operation\'s own exception follows.', ['reason' => $close->getMessage(), 'exception' => $close]);
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

        $this->logger->warning('An audit frame was left open; its {held} held record(s) have been written and the frame closed. Pair begin() with end() in a try/finally, or use coalesce().', ['held' => $held]);

        $this->writer->writeManyCompleted($records);
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
        $held = $this->buffer->count();

        if (!$this->buffer->reset()) {
            return false;
        }

        $this->logger->warning('An audit frame was left open and has been reset; {held} held record(s) were dropped. Pair begin() with end() in a try/finally, or use coalesce().', ['held' => $held]);

        return true;
    }

    public function isOpen(): bool
    {
        return $this->buffer->isOpen();
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
