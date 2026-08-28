<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Hands documents to Messenger; the handlers write them from the worker. The
 * request only pays for the dispatch, and Elasticsearch being slow or down no
 * longer touches response times. A batch travels as one message and becomes one
 * _bulk call on the other side.
 */
final class MessengerTransport implements BatchTransportInterface
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        $this->dispatch(new IndexAuditRecord($index, $document, $id));
    }

    public function sendMany(array $items): BulkResult
    {
        if ($items !== []) {
            $this->dispatch(new IndexAuditRecords($items));
        }

        // Nothing has been written yet, so nothing has failed yet: the worker finds out.
        return BulkResult::allSucceeded(\count($items));
    }

    private function dispatch(object $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            throw TransportUnavailableException::because($e);
        }
    }
}
