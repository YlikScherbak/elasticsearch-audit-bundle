<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

/**
 * "Put this document into that index." Carries plain arrays, not the AuditRecord
 * object, so it serialises with any Messenger serializer and survives a redeploy
 * that changes the model.
 */
final class IndexAuditRecord
{
    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        public readonly string $index,
        public readonly array $document,
    ) {
    }
}
