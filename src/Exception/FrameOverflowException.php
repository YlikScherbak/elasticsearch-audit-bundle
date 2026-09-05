<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * A frame was asked to hold more objects than coalescing.max_held allows, and the
 * deployment chose to be told rather than to have the records released early.
 *
 * Releasing is the default and is safe for the history — nothing is lost — but it
 * ends the promise the frame makes: past that point an object can produce a second
 * record whose net effect over the whole operation was nothing. Where that promise
 * is what the trail is read for, "throw" says so instead of blurring it.
 */
final class FrameOverflowException extends \RuntimeException implements AuditException, SafeExceptionMessage
{
    private int $dropped = 0;

    public static function past(int $maxHeld, int $dropped = 0): self
    {
        $refused = new self(sprintf('The frame is holding %d objects, which is coalescing.max_held. With on_overflow: throw it stops here rather than releasing early and coalescing the rest per object.', $maxHeld));
        $refused->dropped = $dropped;

        return $refused;
    }

    /**
     * How many records the refusal threw away — what the frame was holding, plus what
     * an early release had staged. For the warning that reports the refusal; the caller
     * has no other way to know, since the buffer is empty by the time it catches this.
     */
    public function held(): int
    {
        return $this->dropped;
    }
}
