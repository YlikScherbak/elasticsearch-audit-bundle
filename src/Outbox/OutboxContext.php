<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Outbox;

/**
 * Whether an audit transaction is running in this process, and whether anything has
 * gone wrong inside it.
 *
 * Two facts, one object, because both are asked from places that know nothing about
 * each other: the transport asks whether it is allowed to write at all, and the
 * transaction asks, right before committing, whether the history it is about to
 * commit is whole.
 *
 * The second question is the one that cannot be answered any other way. Symfony's
 * Doctrine transport wraps its insert in a transaction of its own, and DBAL nests
 * those as savepoints — so a failed outbox insert rolls back its savepoint and
 * leaves the surrounding transaction perfectly committable. (DBAL 3 could mark it
 * rollback-only when savepoints were off; 4 always uses them. Neither is something
 * to build a guarantee on.) And the failures that matter most never reach the
 * transport at all: redaction refusing a record, a listener vetoing one, an
 * enricher throwing. If the business code catches such an exception — and code that
 * wraps its own work in try/catch usually does — nothing else would stop the
 * commit.
 *
 * So the writer says so here, and the transaction refuses to commit.
 *
 * @internal how the outbox transport and AuditTransaction agree on what is happening
 */
final class OutboxContext
{
    private int $depth = 0;

    private ?string $spoiled = null;

    /**
     * Whether a record may be written to the outbox right now, which is to say whether
     * anybody is holding a transaction it can be part of.
     */
    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @internal AuditTransaction owns this: it is what makes the two questions above
     *           mean anything, and a second caller would make them mean nothing
     */
    public function enter(): void
    {
        if ($this->depth === 0) {
            // Clean, whatever happened before. What went wrong outside a transaction is
            // not this one's business - and it used to be: reportFailure() marks the
            // context for every failed record, including the ones a plain flush produces
            // with nothing open, and the mark survived to refuse the next transaction's
            // commit. In a worker under require_transaction that fires on every message
            // after the first stray record.
            $this->spoiled = null;
        }

        ++$this->depth;
    }

    /**
     * Leaves, and answers with what went wrong inside — the transaction reports it and
     * the next operation starts clean, whether this one committed or not.
     */
    /**
     * @internal see enter()
     */
    public function leave(): ?string
    {
        if ($this->depth > 0) {
            --$this->depth;
        }

        if ($this->depth > 0) {
            return $this->spoiled;
        }

        $spoiled = $this->spoiled;
        $this->spoiled = null;

        return $spoiled;
    }

    /**
     * Something the history needed did not happen. Said in the caller's own words,
     * because "the transaction cannot commit" is useless without the reason, and by
     * then the exception may have been caught and forgotten three frames up.
     *
     * The first reason wins: it is the one that explains the rest.
     */
    public function spoil(string $reason): void
    {
        // Only while somebody is holding a transaction this could spoil. Outside one
        // there is nothing to refuse: the record failed, the failure policy dealt with
        // it, and remembering it here would only wait for a transaction that has
        // nothing to do with it.
        if ($this->depth === 0) {
            return;
        }

        $this->spoiled ??= $reason;
    }

    public function spoiledBecause(): ?string
    {
        return $this->spoiled;
    }

    /**
     * Back to nothing, for a process that reuses this service between units of work.
     *
     * Tagged kernel.reset, so a Messenger worker starts every message with a context
     * that remembers nothing - and a worker started with --no-reset does not, which is
     * why this is a second line of defence rather than the mechanism: enter() clears
     * what it needs to clear, and spoil() keeps nothing while nothing is open.
     */
    public function reset(): void
    {
        $this->depth = 0;
        $this->spoiled = null;
    }
}
