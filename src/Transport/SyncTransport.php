<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Psr\Clock\ClockInterface;

/**
 * Writes to Elasticsearch in the same request: one call per record, or one _bulk
 * call for a batch. Written when the call returns - and searchable after the index's next
 * refresh, which this does not ask for.
 */
final class SyncTransport implements BatchTransportInterface
{
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        $this->gateway->index($index, WrittenAt::on($document, $this->clock), $id);
    }

    public function sendMany(array $items): BulkResult
    {
        return $this->gateway->bulk(WrittenAt::onEach($items, $this->clock));
    }
}
