<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Reading;

use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\Change;

/**
 * The other kind of decoration: not something *beside* the record, but the record's
 * own value in a form somebody wants to read.
 *
 * `withExtra()` adds; `withChanges()` replaces. Use the second when the stored
 * value is right and its spelling is not — a status code, a permission key, a
 * country code.
 */
final class ReadableStatusDecorator implements RecordDecoratorInterface
{
    private const LABELS = [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'shipped' => 'Shipped',
    ];

    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<AuditEntry>
     */
    public function decorate(array $entries): array
    {
        return array_map(
            static function (AuditEntry $entry): AuditEntry {
                $status = $entry->changes['status'] ?? null;

                // Read back from Elasticsearch a change is the array it was stored as,
                // not a Change object — Change::isPair() recognises that shape.
                if (!Change::isPair($status)) {
                    return $entry;
                }

                return $entry->withChanges(['status' => [
                    'old' => self::LABELS[$status['old']] ?? $status['old'],
                    'new' => self::LABELS[$status['new']] ?? $status['new'],
                ]] + $entry->changes);
            },
            $entries,
        );
    }
}
