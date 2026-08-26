<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;

/**
 * Writes the document to Elasticsearch in the same request.
 * Simple and visible immediately; every write costs one HTTP round-trip.
 */
final class SyncTransport implements TransportInterface
{
    public function __construct(private readonly GatewayInterface $gateway)
    {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        $this->gateway->index($index, $document, $id);
    }
}
