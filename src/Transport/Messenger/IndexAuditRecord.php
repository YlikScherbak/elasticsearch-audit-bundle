<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

/**
 * "Put this document into that index, under this id." Carries plain PHP values only —
 * scalars, arrays and stdClass objects the writer built, never the AuditRecord nor an
 * object the application recorded — so no class of the application's is named in the
 * queue and a redeploy that renames one leaves nothing unreadable. That `{}` stays `{}`
 * after the queue is the serializer's to keep: PhpSerializer does. The id is what makes a
 * redelivery harmless: the same document is written again under the same id.
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
