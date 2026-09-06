<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * The outbox could not do what it promises: keep the record and the change it
 * describes in one commit.
 *
 * Separate from TransportUnavailableException on purpose. That one means "the
 * cluster is not answering", which is a reason to retry later and never a reason to
 * fail the operation — the whole point of writing through a queue is that
 * Elasticsearch being down is not the application's problem. This one means the row
 * did not reach the queue, and the queue is the local database: if it cannot take
 * the record, the change it describes should not be committed either.
 */
final class OutboxException extends \RuntimeException implements AuditException, SafeExceptionMessage
{
    /**
     * Private on purpose: every message this class carries is one the bundle wrote,
     * which is what lets it be repeated where a foreign message would not be. The
     * cause travels as the previous exception, and what may be repeated of it is the
     * failure policy's decision.
     */
    private function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function outsideATransaction(): self
    {
        return new self('The outbox writes an audit record into a SQL queue so that it is committed together with the change it describes, and nothing is holding a transaction here — the row would be durable whether or not that change ever happened, which is the opposite of what turning the outbox on asks for. Wrap the operation in AuditTransaction, or set outbox.require_transaction: false to accept the weaker promise: the record is kept, but not necessarily with the data.');
    }

    public static function couldNotQueue(\Throwable $cause): self
    {
        return new self('An audit record could not be written to the outbox queue. Its table is created by a migration and never by the bundle — auto_setup would run DDL, which on MySQL commits the transaction it is standing in. The cause is the previous exception.', 0, $cause);
    }

    public static function transactionAlreadyOpen(): self
    {
        return new self('AuditTransaction commits the change and its history together, which means the commit has to be its own - and a transaction is already open on this connection. Whoever opened it decides when it commits, and may well commit after catching whatever happens in here. Run this where the transaction begins, or let the code that owns it call AuditTransaction instead.');
    }

    public static function alreadyInside(): self
    {
        return new self('An audit transaction is already running in this process. Nesting them would mean the inner one committing part of the outer one\'s work, or rolling back records that are not its own - the frame behind them is shared, and the records of one object are merged whoever recorded them. Call the inner operation without its own transaction; it is already inside one.');
    }

    public static function immediateWriteInsideATransaction(): self
    {
        return new self('write($record, immediately: true) bypasses the frame and the queue to make one record visible before the request ends, and inside an audit transaction that is the one thing that must not happen: the record would reach Elasticsearch describing a change this transaction may still roll back. Record it normally - it is queued with everything else and delivered by the worker - or do it outside the transaction.');
    }

    public static function frameLeftOpen(): self
    {
        return new self('The operation opened an audit frame of its own and did not close it, so the records it collected are still being held and none of them reached the queue. A commit now would be the change without its history, which is what this transaction exists to prevent - it was rolled back. Pair every begin() with an end() in a try/finally, or use coalesce().');
    }

    public static function cannotCommit(string $reason): self
    {
        return new self(sprintf('The transaction was not committed, because %s. The audit trail is what this transaction exists to keep whole, so a commit that leaves it short is refused rather than made — the business change has been rolled back and can be retried.', $reason));
    }
}
