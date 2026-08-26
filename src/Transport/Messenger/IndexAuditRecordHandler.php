<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;

/**
 * The worker side of MessengerTransport. Exceptions propagate on purpose:
 * Messenger's retry strategy is the right place to deal with a flaky cluster.
 */
final class IndexAuditRecordHandler
{
    public function __construct(private readonly GatewayInterface $gateway)
    {
    }

    public function __invoke(IndexAuditRecord $message): void
    {
        $this->gateway->index($message->index, $message->document);
    }
}
