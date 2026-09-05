<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\FailureReason;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\SafeMessage;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The worker side of MessengerTransport.
 *
 * A cluster that is unreachable propagates as TransportUnavailableException and is
 * retried by Messenger's strategy — the right place for a flaky cluster, and safe,
 * because the document is written under the record's id. A document Elasticsearch
 * refuses will be refused again, so that is raised as unrecoverable: Messenger sends
 * the message to the failure transport at once instead of around the retry loop.
 *
 * A third outcome is neither: IndexNotFoundException. It leaves the handler as itself,
 * so Messenger's default strategy retries it — which is what an index caught
 * mid-rollover needs, and why it is not made unrecoverable. Worth knowing if you key a
 * custom retry strategy on the exception class rather than on the two named above.
 *
 * Whichever of the three it is, it leaves here with no chain behind it. Retries end,
 * and Symfony then keeps the flattened cause in the failure transport for as long as
 * the message sits there.
 *
 * @internal invoked by Messenger
 */
final class IndexAuditRecordHandler
{
    public function __construct(private readonly GatewayInterface $gateway)
    {
    }

    public function __invoke(IndexAuditRecord $message): void
    {
        // The document carries the record's id too, and that copy is what makes a
        // redelivery harmless: written under the same id, the same event is one
        // document. A message reaches here without one only if it was queued before ids
        // existed, or if a serializer dropped a property it had a default for — the
        // second is recoverable, and reading the id out of the document is how. What is
        // left after that is genuinely a message from before ids, which Elasticsearch
        // stores under one of its own.
        $id = $message->id ?? (\is_string($message->document['id'] ?? null) && $message->document['id'] !== '' ? $message->document['id'] : null);

        try {
            $this->gateway->index($message->index, $message->document, $id);
        } catch (TransportUnavailableException|IndexNotFoundException $e) {
            // Retried, so it stays this class — Messenger's strategy keys off it, and a
            // busy cluster or an index mid-rollover must not cost a record. What does
            // not travel is the chain: these carry the client's own exception, whose
            // message is the status line followed by the whole response body, and a 429
            // refusing a document quotes that document. Retries end eventually, and when
            // they do Symfony stores the flattened chain in the failure transport, where
            // it outlives the request that made it. The bundle's own sentence is enough
            // to act on, and it is the same one either way.
            throw SafeMessage::withoutTheChain($e);
        } catch (RequestRejectedException $e) {
            // The chain stops here. Symfony keeps a failed message's cause as an
            // ErrorDetailsStamp built from FlattenException, which walks getPrevious()
            // and keeps every message it finds — and that stamp lives in the failure
            // transport until somebody retries or removes it. The gateway's own sentence
            // is cut and safe; the client's exception behind it is the status line
            // followed by the whole response body, which is where a refused document's
            // values are. FailureReason keeps the first and drops the second.
            $safe = FailureReason::keepingTheMessageOf($e);

            throw new UnrecoverableMessageHandlingException($safe->getMessage(), 0, $safe);
        }
    }
}
