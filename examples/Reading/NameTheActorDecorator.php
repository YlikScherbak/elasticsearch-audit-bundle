<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Reading;

use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;

/**
 * Making a page readable.
 *
 * A record stores identifiers, because a name is a value that changes and a
 * history is not the place to keep a copy of it. A screen wants the name. That
 * lookup belongs here, on the read, and not in the record.
 *
 * The decorator receives the **whole page at once**, which is the point of the
 * interface: one query for the twenty actors on the page, not one per line. A
 * decorator that loads per entry is the N+1 this shape exists to prevent.
 *
 * Implementations are picked up automatically and run in order.
 */
final class NameTheActorDecorator implements RecordDecoratorInterface
{
    /** @param array<string, string> $namesById stands for a repository in a real application */
    public function __construct(private readonly array $namesById)
    {
    }

    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<AuditEntry>
     */
    public function decorate(array $entries): array
    {
        $actors = array_values(array_unique(array_filter(
            array_map(static fn (AuditEntry $entry): ?string => $entry->actor, $entries),
            static fn (?string $actor): bool => $actor !== null && $actor !== '',
        )));

        if ($actors === []) {
            return $entries;
        }

        // One lookup for the page: $this->users->findByIds($actors) in a real one.
        $names = array_intersect_key($this->namesById, array_flip($actors));

        return array_map(
            static fn (AuditEntry $entry): AuditEntry => $entry->withExtra([
                // withExtra() adds beside the record; it is never stored, and never
                // pretends to be — toDocument() does not see it.
                'actorName' => $entry->actor === null ? null : ($names[$entry->actor] ?? $entry->actor),
            ]),
            $entries,
        );
    }
}
