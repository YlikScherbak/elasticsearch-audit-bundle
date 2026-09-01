<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing\Messenger;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Closes a frame a handler left open — because it threw before end(), or because a
 * begin() has no matching end() — so it cannot swallow the next message's history.
 *
 * What the frame held is written, not dropped: a record only reaches the frame after
 * the save that produced it went through (the Doctrine listener hands records over
 * once the transaction committed), so those changes are in the database whether the
 * handler reached its end or not. The warning names the missing try/finally.
 *
 * Add it to the bus, after the handler-facing middleware:
 *
 *   framework:
 *     messenger:
 *       buses:
 *         messenger.bus.default:
 *           middleware:
 *             - Borsche\ElasticsearchAuditBundle\Coalescing\Messenger\FrameResetMiddleware
 */
final class FrameResetMiddleware implements MiddlewareInterface
{
    /** How many consumed messages are on the stack right now: nested synchronous handling counts. */
    private int $consuming = 0;

    public function __construct(private readonly AuditFrame $frame)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Bus middleware runs on dispatch too, and with the messenger transport the
        // writer itself dispatches — from inside the very frame this middleware guards.
        // Releasing here cut an open frame in the middle of its operation: phantom
        // intermediate states, and a warning blaming a try/finally nobody omitted. Only
        // a message being consumed marks a handler boundary.
        if ($envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        try {
            ++$this->consuming;

            return $stack->next()->handle($envelope, $stack);
        } finally {
            // And only the outermost one: a handler may consume another message
            // synchronously, and the frame it opened is still its own.
            if (--$this->consuming === 0) {
                $this->frame->release();
            }
        }
    }
}
