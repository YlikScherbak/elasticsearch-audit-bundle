<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Outbox;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Writer\FailureDetails;
use Doctrine\DBAL\Connection;
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
        // Appended, because the ones above are passed positionally. Two foreign
        // exceptions meet on the rollback path and this says whether either is repeated.
        private readonly FailureDetails $failureDetails = FailureDetails::Cause,
        // What the connection did, where the bundle watches it: told when a session is
        // abandoned, so that what it left unfinished is not read as still going on.
        private readonly ?StatementLog $statements = null,
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

        // What the driver has committed and rolled back so far, where the bundle watches the
        // connection. A count rather than a flag of "ours": the commit this transaction makes
        // is the one after the operation, and anything the count gains before it, the
        // operation did - whoever's code it was.
        $before = $this->statements?->transactionsEnded();

        try {
            $result = $operation();

            // One level opened, one level closed: the operation has to leave the
            // transaction where it found it. Before the frame, so that records of an
            // operation already found unbalanced are not queued only to be undone.
            $this->assertTheOperationLeftOneLevel($before);

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

            // And again: closing the frame ran the application's listeners, and a level one
            // of them opened or closed was not there when the operation returned.
            $this->assertTheOperationLeftOneLevel($before);

            if (!$this->committedSince($before)) {
                $this->commitIfTheHistoryIsWhole();

                return $result;
            }

            // The operation committed the transaction itself, and nothing was rolled back:
            // what the frame describes is committed already, or is about to be by the commit
            // below, so its history is true and is kept. What cannot be kept is the promise
            // of one commit for both, and that is said after - outside the catch, because it
            // is not a reason to undo anything.
            $this->commitTheRest();
        } catch (\Throwable $e) {
            throw $this->undo($e) ?? ($this->committedSince($before) ? OutboxException::committedBeforeFailing($e) : $e);
        } finally {
            $this->context->leave();
        }

        throw OutboxException::operationCommittedItself();
    }

    /**
     * @param array{commits: int, rollbacks: int}|null $before
     */
    private function committedSince(?array $before): bool
    {
        return $before !== null && $this->statements !== null && $this->statements->transactionsEnded()['commits'] > $before['commits'];
    }

    /**
     * After the operation committed run()'s transaction itself: its history goes the same
     * way the rest of its change did. Into the transaction it opened again if there is one,
     * committed with it; and if there is none, the records were queued as they were written,
     * each committed on its own.
     *
     * @throws OutboxException
     */
    private function commitTheRest(): void
    {
        $spoiled = $this->context->spoiledBecause();

        if ($spoiled !== null) {
            throw OutboxException::cannotCommit($spoiled);
        }

        if ($this->connection->getTransactionNestingLevel() === 1) {
            $this->connection->commit();
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
     * A transport that is not a Doctrine one is refused rather than left alone. It was
     * tempting to call that "unfamiliar, so no opinion", and it is not: a queue that
     * writes somewhere other than this connection cannot be committed by this
     * transaction, whatever it is, and saying nothing would leave require_transaction
     * reporting a promise nobody is keeping.
     *
     * @throws OutboxException
     */
    private function assertTheQueueIsOnThisConnection(): void
    {
        if ($this->connectionChecked || $this->queue === null) {
            return;
        }

        if (!$this->queue instanceof DoctrineTransport) {
            // Not "nothing to check": the check failed. Only a Doctrine transport writes
            // its row on this connection, and only that row is committed by this
            // transaction - anything else is a message going somewhere else entirely,
            // durable or not, while require_transaction reports itself satisfied. The
            // boot says so too when the DSN can be read, and the DSN most applications
            // write cannot be.
            throw OutboxException::queueIsNotTransactional($this->queue::class);
        }

        if (!WhereTheQueueWrites::isOn($this->queue, $this->connection)) {
            throw OutboxException::queueOnAnotherConnection();
        }

        // Only now. Set before the verdict, a refusal would have been remembered as an
        // answer: a worker catches the exception, takes the next message, and this
        // returns early on a queue that was already found to be on the wrong
        // connection - which turns a loud refusal into a silent one exactly in the
        // long-running process the outbox exists for.
        $this->connectionChecked = true;
    }

    /**
     * The one level run() opened, and nothing above or below it.
     *
     * Above it, one commit() would close only the innermost level - on DBAL 4 it releases
     * a savepoint - and run() would return with the change and its history uncommitted,
     * the transaction left open for whatever runs next on the connection. Below it, the
     * operation ended run()'s transaction itself, and whether that was a commit or a
     * rollback is not something the level says.
     *
     * Where the connection is watched, the level is not all there is to ask: an operation
     * that committed or rolled back the transaction and began another leaves it at one. The
     * driver's own count says so. A rollback means the frame may describe what was undone,
     * and nothing in it is kept; a commit is let through to the caller, which decides.
     *
     * @param array{commits: int, rollbacks: int}|null $before
     *
     * @throws OutboxException
     */
    private function assertTheOperationLeftOneLevel(?array $before): void
    {
        $ended = $before !== null && $this->statements !== null ? $this->statements->transactionsEnded() : null;

        if ($ended !== null && $ended['rollbacks'] > $before['rollbacks']) {
            throw OutboxException::transactionRolledBackInside();
        }

        $level = $this->connection->getTransactionNestingLevel();

        if ($level > 1) {
            throw OutboxException::transactionLeftOpen($level - 1);
        }

        // Below one, with no commit counted, nothing can tell a commit from a rollback - and
        // without a log to count them, neither can anything here.
        if ($level < 1 && ($ended === null || $ended['commits'] === $before['commits'])) {
            throw OutboxException::transactionEndedInside();
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
     *
     * @return OutboxException|null what to raise instead of the operation's own failure:
     *                              said when the rollback failed and the session had to be
     *                              abandoned, because then the caller has more to do
     */
    private function undo(\Throwable $cause): ?OutboxException
    {
        $abandoned = null;

        try {
            try {
                // Every level, down to none - run() refuses to start inside a transaction,
                // so all of them are this one's: the operation may have left a level open,
                // and one rollBack() would close that one and leave the outer transaction
                // holding the rows of an operation that failed. Counted down from the level
                // found, so a driver that does not lower it cannot keep this going.
                for ($levels = $this->connection->getTransactionNestingLevel(); $levels > 0 && $this->connection->isTransactionActive(); --$levels) {
                    $this->connection->rollBack();
                }
            } catch (\Throwable $rollback) {
                // Reported rather than raised: the caller needs the reason the operation
                // failed, and a database that cannot even roll back is a second problem
                // rather than the answer to the first.
                //
                // Both causes travel the failure policy, and neither of them is the
                // bundle's own text. The operation threw whatever the application throws,
                // and applications throw exceptions that quote the values they were
                // holding; the driver quotes the statement it could not undo. Attaching
                // either to the context would be that policy walked around one step
                // later — every exception logger an application has serialises a chain.
                $this->report(
                    'The audit transaction could not roll back after {reason}: {rollback}.',
                    [
                        'reason' => $this->failureDetails->of($cause)->getMessage(),
                        'rollback' => $this->failureDetails->of($rollback)->getMessage(),
                    ],
                    $rollback,
                );

                $abandoned = $this->abandon($cause);
            }
        } finally {
            // Every level rather than just the outermost: the operation may have left one
            // open, which is one of the reasons to be here.
            //
            // In a finally as well, and the two guards here cover different halves of the
            // same accident. report() swallowing what the logger throws is what keeps the
            // caller's own exception — a logger that is down is not the answer to "why did
            // my operation fail". This finally is what keeps the frame from holding the
            // records of an undone operation for whatever runs next. With the swallow in
            // place nothing reaches past it, so this one is not observable on its own
            // today; it is here for the day a line is added above it that can throw.
            $this->frame->dropEverything();
        }

        return $abandoned;
    }

    /**
     * Ends a session whose transaction could not be rolled back.
     *
     * Left as it was, the connection would carry a transaction nobody can describe into
     * whatever runs next on it - the next request's writes inside it, uncommitted for as
     * long as the process lives. Closed, it is over: the database discards what an
     * unfinished transaction held when its connection drops, and DBAL opens a new one on
     * the next use. That is a reason to close it, not a claim that anything was rolled
     * back, and neither the log nor the exception says it was.
     */
    private function abandon(\Throwable $cause): OutboxException
    {
        try {
            $this->connection->close();
        } catch (\Throwable $close) {
            $this->report('The audit transaction could not close its connection after a failed rollback: {reason}.', ['reason' => $this->failureDetails->of($close)->getMessage()], $close);
        }

        $this->statements?->sessionEnded();

        return OutboxException::sessionAbandoned($cause);
    }

    /**
     * Says it, and does not let saying it become the failure.
     *
     * The caller is already inside a catch: whatever comes out of here replaces the
     * exception the application is waiting for, and "your logger is down" is not the
     * answer to "why did my operation fail". Under `full` the cause still travels,
     * because that setting says to repeat what other code said.
     *
     * @param array<string, mixed> $context
     */
    private function report(string $message, array $context, \Throwable $cause): void
    {
        if ($this->failureDetails === FailureDetails::Full) {
            $context['exception'] = $cause;
        }

        try {
            $this->logger->error($message, $context);
        } catch (\Throwable) {
            // Nowhere left to say it.
        }
    }
}
