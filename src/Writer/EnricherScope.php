<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ScopedEnricherInterface;

/**
 * Reads what an enricher said about the object types it is for.
 *
 * One place because two ask, and their answers have to be the same one: the writer,
 * about a record in front of it, and the index commands, about an index that has no
 * records yet. A field mapped into an index the writer never sends it to is clutter; a
 * field the writer sends into an index whose mapping does not have it is worse —
 * `dynamic: false` stores it and never indexes it, so it is there and unsearchable.
 *
 * @internal
 */
final class EnricherScope
{
    /**
     * Whether this enricher takes part in records of this object type.
     */
    public static function covers(AuditEnricherInterface $enricher, string $objectType): bool
    {
        $types = self::typesOf($enricher);

        return $types === [] || \in_array($objectType, $types, true);
    }

    /**
     * Whether this enricher's fields can turn up in this index.
     *
     * Read through the resolver rather than by comparing names: routing is what turns
     * an object type into an index, and it is the resolver that knows it — including
     * the part nobody writes down, that everything unrouted goes to the default.
     */
    public static function reaches(AuditEnricherInterface $enricher, IndexResolver $resolver, string $index): bool
    {
        $types = self::typesOf($enricher);

        if ($types === []) {
            // Said nothing, so it is about everything — which is what every enricher
            // written before this interface existed says, and why they keep working.
            return true;
        }

        foreach ($types as $objectType) {
            if ($resolver->resolve($objectType) === $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function typesOf(AuditEnricherInterface $enricher): array
    {
        return $enricher instanceof ScopedEnricherInterface ? $enricher->objectTypes() : [];
    }
}
