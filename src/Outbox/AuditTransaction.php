<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Outbox;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

/**
 * One commit for the change and its history.
 *
 *   $transaction->run(fn () => $this->orders->approve($order));
 *
 * Everything the operation records is held by a frame, written into the outbox
 * queue while the transaction is still open, and committed with the rows it
 * describes. If anything fails — the operation, the audit, the queue — the whole of
 * it rolls back, and there is no state where the order was approved and nobody can
 * say who approved it.
 *
 * **The order matters and is the opposite of the plain frame recipe.** There, the
 * frame closes *after* the commit, because closing it writes to Elasticsearch and
 * that must not happen for a transaction that is about to roll back. Here closing
 * it writes to the same database, inside the same transaction, so it has to happen
 * *before* the commit — and a failure while closing rolls the operation back rather
 * than being logged after the fact.
 *
 * **What it does not promise.** Elasticsearch is not part of the transaction and
 * cannot be: what is guaranteed is that the record is durable and will be
 * delivered, not that it is searchable by the time run() returns. A worker moves it
 * on, retries what fails, and the document's stable id makes a redelivery harmless.
 *
 * **After a rollback the EntityManager is out of step with the database**, exactly as
 * it is after any hand-rolled transaction: entities it still holds describe rows that
 * no longer exist. Clear or reset it before carrying on, the way Doctrine's own
 * wrapInTransaction() leaves you to.
 *
 * And the guarantee is about what the bundle can see: entities audited by their
 * declarations, and records the application writes itself. A DQL bulk update, a
 * native DELETE, another application on the same database — none of those pass
 * through here.
 */
final class AuditTransaction
{
    private readonly LoggerInterface $logger;

    /**
     * @param object|null $queue the Messenger transport the records are written into,
     *                           asked once whether it is holding this connection
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly AuditFrame $frame,
        private readonly OutboxContext $context,
        ?LoggerInterface $logger = null,
        private readonly ?object $queue = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether the queue has been asked which connection it holds. Once per process is
     * enough: services do not change connections between operations.
     */
    private bool $connectionChecked = false;

    /**
     * Runs the operation, and commits only if its history is whole.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws OutboxException      the history could not be kept, so the change was rolled back
     * @throws WriteFailedException the same outcome under on_failure: throw, where a failed
     *                              record surfaces as the writer's own exception rather than
     *                              as this one - code that catches only OutboxException
     *                              would miss it, and both mean the operation did not happen
     */
    public function run(callable $operation): mixed
    {
        $this->assertTheQueueIsOnThisConnection();

        // The nested case first, because it is the more precise answer to the same
        // observation: a transaction is open, and this is the one that opened it.
        if ($this->context->isOpen()) {
            throw OutboxException::alreadyInside();
        }

        // Ours or nobody's. The whole guarantee is that this commit is the one both the
        // rows and the records ride on, and a transaction somebody else opened commits
        // when they say so — possibly around several of these, possibly after catching
        // whatever this raises.
        if ($this->connection->isTransactionActive()) {
            throw OutboxException::transactionAlreadyOpen();
        }

        // Before the transaction: begin() refuses a frame that is not the outermost one,
        // and finding that out after BEGIN would leave a transaction to unwind.
        $this->frame->begin(atomic: true);
        $this->context->enter();

        try {
            $this->connection->beginTransaction();
        } catch (\Throwable $e) {
            $this->context->leave();
            $this->frame->dropEverything();

            throw $e;
        }

        try {
            $result = $operation();

            // The records go into the queue here, inside the transaction. A failure is
            // the operation's failure now, not a line in a log after the fact.
            $this->frame->end();

            // One end() closes one level, and the operation may have opened another and
            // forgotten it. The frame then holds everything it collected, close() writes
            // nothing, and without this the commit would go ahead with an empty queue
            // and a buffer still open for whatever runs next.
            if ($this->frame->isOpen()) {
                throw OutboxException::frameLeftOpen();
            }

            $this->commitIfTheHistoryIsWhole();

            return $result;
        } catch (\Throwable $e) {
            $this->undo($e);

            throw $e;
        } finally {
            $this->context->leave();
        }
    }

    /**
     * Asks the queue itself which connection it is holding, before the first operation
     * rather than after it.
     *
     * The boot compares the DSN, which is the right check where a DSN can be read and
     * no check at all where it comes from an environment variable — which is most
     * production applications. And a DSN is a description of a connection rather than
     * the connection: a factory of somebody's own could hand back a different one
     * while spelling the same string.
     *
     * So this asks the object. configureSchema() means "add your table to this schema
     * if this is your connection", and the closure that would let it say "near enough,
     * same database" answers no — so a table in the schema means the same connection
     * instance and nothing else does. Nothing is written, nothing is created, nothing
     * is even sent to the database.
     *
     * Fail-open for anything that cannot answer: a transport of somebody's own is not
     * something to refuse on the grounds that it is unfamiliar, and audit:check reports
     * that case in words.
     *
     * @throws OutboxException
     */
    private function assertTheQueueIsOnThisConnection(): void
    {
        if ($this->connectionChecked || !$this->queue instanceof DoctrineTransport) {
            return;
        }

        $this->connectionChecked = true;

        // The schema handed in is the one to read: the method returns void on Symfony
        // 6.4 and a Schema on 7, and fills in what it was given on both.
        $schema = new Schema();
        $this->queue->configureSchema($schema, $this->connection, static fn (): bool => false);

        if ($schema->getTables() === []) {
            throw OutboxException::queueOnAnotherConnection();
        }
    }

    /**
     * @throws OutboxException
     */
    private function commitIfTheHistoryIsWhole(): void
    {
        // Asked, not assumed. Under on_failure: log a record that could not be built,
        // redacted or queued never reaches the caller as an exception — the writer's
        // whole purpose is that the audit log cannot take an operation down. That is the
        // right default everywhere except here, where the operation exists to keep the
        // history whole. Nothing else would stop this commit: a failed insert rolls back
        // its own savepoint and leaves the transaction perfectly committable, and a
        // veto or a redaction refusal never touches the database at all.
        $spoiled = $this->context->spoiledBecause();

        if ($spoiled !== null) {
            throw OutboxException::cannotCommit($spoiled);
        }

        $this->connection->commit();
    }

    /**
     * Rolls back both halves. The frame is reset rather than closed: what it holds
     * describes rows that are being undone.
     */
    private function undo(\Throwable $cause): void
    {
        try {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        } catch (\Throwable $rollback) {
            // Reported rather than raised: the caller needs the reason the operation
            // failed, and a database that cannot even roll back is a second problem
            // rather than the answer to the first.
            $this->logger->error('The audit transaction could not roll back after {reason}: {rollback}.', [
                'reason' => $cause->getMessage(),
                'rollback' => $rollback->getMessage(),
                'exception' => $rollback,
            ]);
        }

        // Every level, not just the outermost: the operation may have left one open,
        // which is one of the reasons to be here.
        $this->frame->dropEverything();
    }
}
