<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Something only the outermost level of a frame may do was asked for inside a
 * nested one.
 *
 * Frames nest, and the buffer behind them does not: they share one set of held
 * records, and the records of one object are merged whoever recorded them — an
 * order's status from the outer operation and its total from the inner one are one
 * record, not two. So a nested level cannot drop "its own" history: there is no
 * such thing to drop.
 *
 * Refusing is the honest answer, and quieter than the alternative was. Dropping
 * everything, which is what used to happen, took the enclosing operation's records
 * with it and closed its frame; what that operation recorded afterwards then went
 * to the index unmerged, from a frame it believed was still open.
 */
final class FrameNestingException extends \LogicException implements AuditException, SafeExceptionMessage
{
    /**
     * Private on purpose: every message this class carries is one the bundle wrote, and
     * that is what lets it be repeated where a foreign message would not be.
     */
    private function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function cannotResetFromInside(): self
    {
        return new self('reset() drops everything the frame holds, and inside a nested frame that is somebody else\'s operation as well as yours — the records of one object are merged whoever recorded them, so there is no "yours" to drop. Let this level end() and leave the decision to whoever opened the outermost frame, or open your own frame around a unit of work that is not nested inside one.');
    }
}
