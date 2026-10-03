<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;

/**
 * A batch transport that only counts what it is handed.
 */
final class CountingBatchTransport implements BatchTransportInterface
{
    public int $batches = 0;

    public int $singles = 0;

    public function send(string $index, array $document, ?string $id = null): void
    {
        ++$this->singles;
    }

    public function sendMany(array $items): BulkResult
    {
        ++$this->batches;

        return BulkResult::empty();
    }
}
