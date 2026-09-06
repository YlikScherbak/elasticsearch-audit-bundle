<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Writing;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One operation, one record.
 *
 * Approving an order saves twice: the status first, so the rest of the system can
 * see it, then the recomputed total. Each flush fires its own events, so the
 * history showed two lines for one thing a person did — and the first of them
 * carried a total nobody ever meant to be visible.
 *
 * A frame around the operation gives one record per object with the values before
 * and after the whole thing.
 */
final class ApprovingAnOrder
{
    public function __construct(
        private readonly AuditFrame $frame,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The usual form. `coalesce()` opens the frame, closes it whatever happens, and
     * returns what the operation returned.
     *
     * Frames nest — call another service that opens one and only the outermost
     * writes — so this is safe to use anywhere without knowing who else does.
     */
    public function approve(Order $order): void
    {
        $this->frame->coalesce(function () use ($order): void {
            $order->status = 'approved';
            $this->em->flush();

            $order->totalCents = $this->recomputeTotal($order);
            $this->em->flush();
        });
    }

    /**
     * The same thing without a closure, for code that cannot wrap one. Pair
     * `begin()` with `end()` in a `try`/`finally` — the frame lives in a service a
     * worker shares between messages, so one left open would swallow the next
     * message's history too.
     */
    public function approveWithoutAClosure(Order $order): void
    {
        $this->frame->begin();

        try {
            $order->status = 'approved';
            $this->em->flush();

            $order->totalCents = $this->recomputeTotal($order);
            $this->em->flush();
        } finally {
            $this->frame->end();
        }
    }

    /**
     * What the frame is for, seen from the trail. A field that moved and came back
     * inside the operation is dropped: reversing a line and applying it again
     * leaves the quantity where it was, and an update in which nothing moved is not
     * written at all — context alone is not history.
     */
    public function reprice(Order $order, int $to): void
    {
        $this->frame->coalesce(function () use ($order, $to): void {
            $wasCents = $order->totalCents;

            $order->totalCents = 0;          // the reversal half of the operation
            $this->em->flush();

            $order->totalCents = $to === 0 ? $wasCents : $to;
            $this->em->flush();
        });
    }

    private function recomputeTotal(Order $order): int
    {
        $total = 0;

        foreach ($order->lines as $line) {
            $total += $line->quantity * 1000;
        }

        return $total;
    }
}
