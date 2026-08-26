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
     *
     * @throws TransportUnavailableException
     */
    public function send(string $index, array $document): void;
}
