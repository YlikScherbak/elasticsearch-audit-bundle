<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
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
 * @internal invoked by Messenger
 */
final class IndexAuditRecordHandler
{
    public function __construct(private readonly GatewayInterface $gateway)
    {
    }

    public function __invoke(IndexAuditRecord $message): void
    {
        try {
            $this->gateway->index($message->index, $message->document, $message->id);
        } catch (RequestRejectedException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }
}
