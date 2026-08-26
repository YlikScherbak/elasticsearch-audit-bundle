<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport;

use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;

/**
 * Carries a finished document to its index — straight away, or through a queue.
 */
interface TransportInterface
{
    /**
     * @param array<string, mixed> $document
     * @param string|null          $id       the document id — the same id written twice is one document
     *
     * @throws TransportUnavailableException
     */
    public function send(string $index, array $document, ?string $id = null): void;
}
