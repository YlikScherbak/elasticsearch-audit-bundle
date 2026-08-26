<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

/**
 * "Put this document into that index, under this id." Carries plain values, not
 * the AuditRecord object, so it serialises with any Messenger serializer and
 * survives a redeploy that changes the model. The id is what makes a redelivery
 * harmless: the same document is written again under the same id.
 */
final class IndexAuditRecord
{
    /**
     * @param array<string, mixed> $document
     * @param string|null          $id       null only for messages queued by a version before ids existed
     */
    public function __construct(
        public readonly string $index,
        public readonly array $document,
        public readonly ?string $id = null,
    ) {
    }
}
