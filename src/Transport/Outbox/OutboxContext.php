<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Outbox;

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

    public function enter(): void
    {
        ++$this->depth;
    }

    /**
     * Leaves, and answers with what went wrong inside — the transaction reports it and
     * the next operation starts clean, whether this one committed or not.
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
        $this->spoiled ??= $reason;
    }

    public function spoiledBecause(): ?string
    {
        return $this->spoiled;
    }
}
