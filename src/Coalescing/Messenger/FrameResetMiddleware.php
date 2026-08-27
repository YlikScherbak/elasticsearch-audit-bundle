<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing\Messenger;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

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
    public function __construct(private readonly AuditFrame $frame)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->frame->release();
        }
    }
}
