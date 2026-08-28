<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

/**
 * "Put these documents into their indices" — one message for the whole batch a
 * flush or a frame produced, so the request pays for one dispatch and the worker
 * makes one _bulk call. Plain arrays, like IndexAuditRecord, for the same reason.
 *
 * @phpstan-import-type BatchItem from \Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface
 */
final class IndexAuditRecords
{
    /**
     * @param list<BatchItem> $items
     */
    public function __construct(public readonly array $items)
    {
    }
}
