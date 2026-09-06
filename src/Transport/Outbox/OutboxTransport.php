<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Outbox;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Writes the finished document into a SQL queue on the application's own database
 * connection, so that it is committed by the same transaction as the change it
 * describes — or by neither.
 *
 * This is the gap the other transports cannot close. `sync` writes to Elasticsearch
 * after the flush committed, and a process that dies in between leaves a change
 * with no history. `messenger` moves the write out of the request, which helps with
 * latency and not at all with atomicity: dispatching to a broker is not part of the
 * database transaction either. Here the row and the record are one commit.
 *
 * **It sends to the queue directly rather than through a bus.** Symfony's Doctrine
 * transport is a `SenderInterface`, and calling it is one INSERT on the connection —
 * which DBAL nests inside whatever transaction is already open. Going through a bus
 * would put the application's middleware between the record and that INSERT, and two
 * of the usual ones are exactly wrong here: `DispatchAfterCurrentBusMiddleware` holds
 * a message until the current one is handled (past the commit, if the transaction is
 * a middleware too), and `DoctrineTransactionMiddleware` flushes on the way through,
 * which re-enters the lifecycle this record was just built from. Bypassing the bus
 * costs nothing — the message is delivered by a worker exactly as before, handled by
 * the same handlers — and removes both hazards rather than asking every application
 * to check its stack.
 *
 * What it does not do is decide when the transaction commits. `AuditTransaction`
 * owns that.
 */
final class OutboxTransport implements BatchTransportInterface
{
    /**
     * @param bool $onlyInsideATransaction refuse to write when nobody is holding a
     *                                     transaction this row could be part of
     */
    public function __construct(
        private readonly SenderInterface $queue,
        private readonly OutboxContext $context,
        private readonly bool $onlyInsideATransaction = true,
    ) {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        $this->put(new IndexAuditRecord($index, $document, $id));
    }

    public function sendMany(array $items): BulkResult
    {
        if ($items !== []) {
            $this->put(new IndexAuditRecords($items));
        }

        // Nothing has reached Elasticsearch yet, and nothing has failed yet: the row is
        // in the queue, and the worker finds out. The same answer the messenger
        // transport gives, for the same reason.
        return BulkResult::allSucceeded(\count($items));
    }

    private function put(object $message): void
    {
        // Refusing rather than writing a row nobody's transaction will commit. Turning
        // the outbox on is easy to read as "the history is now atomic with the data",
        // and outside a transaction it is not: the row is durable, and it is durable
        // whether or not the change it describes ever happened. Applications that want
        // the weaker promise say so in configuration.
        if ($this->onlyInsideATransaction && !$this->context->isOpen()) {
            throw OutboxException::outsideATransaction();
        }

        try {
            $this->queue->send(new Envelope($message));
        } catch (\Throwable $e) {
            // Said here as well as thrown, because throwing is not enough on its own:
            // under on_failure: log the writer will catch this and carry on, and the
            // business transaction would commit a change whose record never reached the
            // queue. The transaction asks the context before it commits.
            $this->context->spoil(sprintf('an audit record could not be written to the outbox (%s)', $e::class));

            throw OutboxException::couldNotQueue($e);
        }
    }
}
