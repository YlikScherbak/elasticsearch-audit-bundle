<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Coalescing\Messenger;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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

    private readonly LoggerInterface $logger;

    public function __construct(private readonly AuditFrame $frame, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Bus middleware runs on dispatch too, and with the messenger transport the
        // writer itself dispatches — from inside the very frame this middleware guards.
        // Releasing here cut an open frame in the middle of its operation: phantom
        // intermediate states, and a warning blaming a try/finally nobody omitted.
        //
        // A consumed message is the handler boundary this middleware exists for, but the
        // stamp alone does not identify one: SyncTransport::send() re-dispatches through
        // the bus with a ReceivedStamp of its own, so a message routed to sync:// —
        // IndexAuditRecords in a dev configuration, or any domain message dispatched
        // from inside coalesce() — arrives looking exactly like a worker's. The frame
        // decides instead: one that is already open when a consume starts was opened by
        // whoever is calling, and closing it is not this middleware's business.
        if ($envelope->last(ReceivedStamp::class) === null || $this->frame->isOpen()) {
            return $stack->next()->handle($envelope, $stack);
        }

        ++$this->consuming;

        try {
            $result = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $failed) {
            // The handler's own exception is what Messenger decides everything by:
            // whether to retry, which failure transport, what the alert says. An audit
            // write that fails while cleaning up after it must not take its place —
            // the same rule AuditFrame::coalesce() follows, and for the same reason.
            if (--$this->consuming === 0) {
                try {
                    $this->frame->release();
                } catch (\Throwable $release) {
                    $this->logger->error('An audit frame left open by a failing handler could not be released: {reason}. The handler\'s own exception follows.', ['reason' => $release->getMessage(), 'exception' => $release]);
                }
            }

            throw $failed;
        }

        // Nothing to mask: the handler succeeded, so a failed release under
        // on_failure: throw is the only thing that went wrong, and the caller hears it.
        // And only the outermost consume closes: a handler may consume another message
        // synchronously, and the frame it opened is still its own.
        if (--$this->consuming === 0) {
            $this->frame->release();
        }

        return $result;
    }
}
