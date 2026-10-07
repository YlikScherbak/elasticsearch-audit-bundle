<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;

/**
 * Writes to Elasticsearch in the same request: one call per record, or one _bulk
 * call for a batch. Written when the call returns - and searchable after the index's next
 * refresh, which this does not ask for.
 */
final class SyncTransport implements BatchTransportInterface
{
    public function __construct(private readonly GatewayInterface $gateway)
    {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        $this->gateway->index($index, $document, $id);
    }

    public function sendMany(array $items): BulkResult
    {
        return $this->gateway->bulk($items);
    }
}
