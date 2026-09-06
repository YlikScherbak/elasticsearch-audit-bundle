<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Outbox;

use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;

/**
 * The transport `write($record, immediately: true)` uses, with one thing taken away
 * while an audit transaction is running.
 *
 * That call exists for the rare record a screen has to show before the request ends,
 * and it gets there by skipping both the frame and the queue. Inside a transaction
 * that is precisely wrong: the document would reach Elasticsearch describing a change
 * that may still roll back, and nothing could take it back afterwards — the index has
 * no transaction to belong to.
 *
 * Refusing is the only honest answer. Queueing it instead would be quieter and would
 * mean the opposite of what the caller asked for, and letting it through would put a
 * record of something that never happened into the one place that is read as
 * evidence.
 */
final class ImmediateTransportGuard implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $immediate,
        private readonly OutboxContext $context,
    ) {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        if ($this->context->isOpen()) {
            // Marked as well as thrown, for the reason every other refusal here is:
            // under on_failure: log the writer catches this and carries on, and the
            // transaction would commit an operation one of whose records was refused.
            // A caller that meant it can move the call outside the transaction; a
            // caller that did not has a bug, and either way the commit is not honest.
            $this->context->spoil('a record was written with immediately: true inside the transaction, which would have reached Elasticsearch before the commit');

            throw OutboxException::immediateWriteInsideATransaction();
        }

        $this->immediate->send($index, $document, $id);
    }
}
