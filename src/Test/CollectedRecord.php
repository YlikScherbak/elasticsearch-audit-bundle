<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Test;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;

/**
 * One record the collector caught, as it would have reached Elasticsearch.
 *
 * The document is the real one — redacted, enriched, routed and carrying its id — so a
 * test asserting on it is asserting on what would have been stored and not on what the
 * application asked for. `entry()` reads it back through the same model the reader
 * returns, which is what makes an assertion here and a query in production agree about
 * what a field is called.
 */
final class CollectedRecord
{
    private ?AuditEntry $entry = null;

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        public readonly string $index,
        public readonly array $document,
        public readonly ?string $id = null,
    ) {
    }

    /**
     * The record as the reader would hand it back: objectType, objectId, event,
     * loggedAt, actor, changes, and everything else under attributes.
     */
    public function entry(): AuditEntry
    {
        return $this->entry ??= AuditEntry::fromHit(['_id' => $this->id ?? '', '_source' => $this->document]);
    }

    /**
     * Whether this record is about that object — the question nearly every assertion
     * starts with. A null objectId or event means "any".
     */
    public function isAbout(string $objectType, int|string|null $objectId = null, ?string $event = null): bool
    {
        $entry = $this->entry();

        if ($entry->objectType !== $objectType) {
            return false;
        }

        // Compared as strings: an id is a keyword in the mapping unless the application
        // said otherwise, so 42 and "42" are the same record and a test should not have
        // to know which of the two its entity handed over.
        if ($objectId !== null && (string) $entry->objectId !== (string) $objectId) {
            return false;
        }

        return $event === null || $entry->event === $event;
    }

    /**
     * What this record is, in one line, for the message of a failing assertion.
     */
    public function describe(): string
    {
        $entry = $this->entry();

        return sprintf('%s %s#%s (%s)', $entry->event, $entry->objectType, $entry->objectId, $this->index);
    }
}
