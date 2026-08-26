<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Hands the document to Messenger; IndexAuditRecordHandler writes it from the worker.
 * The request only pays for the dispatch, and Elasticsearch being slow or down no
 * longer touches response times.
 */
final class MessengerTransport implements TransportInterface
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        try {
            $this->bus->dispatch(new IndexAuditRecord($index, $document, $id));
        } catch (\Throwable $e) {
            throw TransportUnavailableException::because($e);
        }
    }
}
