<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Operating;

use Borsche\ElasticsearchAuditBundle\Examples\Writing\Order;
use Borsche\ElasticsearchAuditBundle\Outbox\AuditTransaction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One commit for the change and its history: `transport: outbox` plus the service
 * that owns the transaction.
 *
 * Everything the operation records is held, written into a SQL queue on the same
 * connection while the transaction is still open, and committed with the rows it
 * describes. A worker moves the queue on to Elasticsearch afterwards. There is no
 * moment where the order is approved and nobody can say who approved it.
 *
 * What this costs, said plainly, because it is not free:
 *
 * - the operation must own its transaction. `AuditTransaction` refuses to run
 *   inside one somebody else opened, because that one commits when they say so;
 * - it does not nest. The frame behind it is shared, so an inner transaction would
 *   be rolling back records that are not its own;
 * - `write($record, immediately: true)` is refused inside it — that call exists to
 *   reach Elasticsearch before the request ends, which is exactly what must not
 *   happen for a change that may still roll back;
 * - after a rollback the EntityManager is out of step with the database, as it is
 *   after any hand-rolled transaction. Clear or reset it.
 *
 * And what it does not promise: the record is durable and will be delivered, not
 * that it is searchable by the time run() returns. Elasticsearch cannot be part of
 * a database transaction, and nothing here pretends otherwise.
 */
final class ApprovingAnOrderAtomically
{
    public function __construct(
        private readonly AuditTransaction $transaction,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function approve(Order $order): void
    {
        $this->transaction->run(function () use ($order): void {
            $order->status = 'approved';
            $this->em->flush();

            $order->totalCents = $this->recomputeTotal($order);
            $this->em->flush();
        });
    }

    /**
     * The failure worth handling, and the one worth not handling.
     *
     * `OutboxException` means the history could not be kept, so the change was rolled
     * back — the operation did not happen and can be retried. Catching it to carry on
     * regardless is the one thing that would make the whole arrangement pointless.
     */
    public function approveAndTellTheUser(Order $order): string
    {
        try {
            $this->approve($order);

            return 'approved';
        } catch (\Borsche\ElasticsearchAuditBundle\Exception\OutboxException $e) {
            // The EntityManager still holds an order that says "approved" and a row that
            // does not. Whatever happens next starts from a clean manager.
            $this->em->clear();

            return 'not approved: '.$e->getMessage();
        }
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
