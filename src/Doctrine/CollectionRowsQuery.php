<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * The query that counts the rows a collection stands for, built from its mapping.
 *
 * It exists apart from the listener for one reason: **its branches are the ones no
 * arrangement of entities can reach.** One arm of each shape test belongs to the ORM major
 * that is not installed; a join table with a schema needs a connection with two schemas; an
 * identifier that is an object needs an entity mapped to one. Left inside the listener,
 * those were a dozen mutants nothing could kill and a paragraph apologising for them. Here
 * the mapping is a parameter, so a test hands one over and reads the SQL back.
 *
 * What it does not do is run anything. The caller owns the connection, the failure policy
 * and the decision the answer feeds; this turns a mapping into a statement and says no by
 * returning null.
 *
 * @internal
 */
final class CollectionRowsQuery
{
    /**
     * The statement counting what the owner still has, or null if the mapping cannot say.
     *
     * Two shapes, because an audited to-many has two. An owning ManyToMany is rows of a
     * join table, keyed by the owner's own columns. An inverse OneToMany is rows of the
     * elements' table carrying the owner's key — which is where a replaced collection with
     * orphanRemoval deletes them, in one statement and with no lifecycle event, so it is
     * the only witness there is for that.
     *
     * Null for anything else: a mapping with neither shape, one whose join columns are
     * missing, a column whose name the mapping does not give, or an owner column that is
     * not a field of the owner. A caller reading null has not been told the rows are
     * there; it has been told nothing.
     *
     * @param mixed                       $mapping the collection's own mapping
     * @param ClassMetadata<object>|null  $target  the elements' metadata, for the inverse
     *                                             shape; unused by the other
     * @param ClassMetadata<object>       $owner
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType|string>}|null
     */
    public static function counting(
        AbstractPlatform $platform,
        ClassMetadata $owner,
        object $entity,
        mixed $mapping,
        ?ClassMetadata $target,
    ): ?array {
        $joinTable = self::entry($mapping, 'joinTable');

        if ($joinTable !== null) {
            $table = self::entry($joinTable, 'name');
            $schema = self::entry($joinTable, 'schema');
            $columns = self::entry($joinTable, 'joinColumns');
        } elseif ($target !== null && \is_string($mappedBy = self::entry($mapping, 'mappedBy'))) {
            $table = $target->getTableName();
            $schema = self::entry($target->table, 'schema');
            $columns = self::entry($target->getAssociationMapping($mappedBy), 'joinColumns');
        } else {
            return null;
        }

        if (!\is_string($table) || !\is_array($columns) || $columns === []) {
            return null;
        }

        $where = [];
        $values = [];
        $types = [];

        foreach ($columns as $column) {
            $name = self::entry($column, 'name');
            $referenced = self::entry($column, 'referencedColumnName');

            if (!\is_string($name) || !\is_string($referenced)) {
                return null;
            }

            $of = $owner->getFieldForColumn($referenced);
            $where[] = $platform->quoteIdentifier($name).' = ?';
            $values[] = $owner->getFieldValue($entity, $of);
            // The column's own type, so that an identifier which is an object — a UUID
            // stored as binary, say — is converted the way the column stores it rather
            // than handed over as whatever it happens to cast to. A field with no declared
            // type gets the behaviour a query with no types at all would have.
            $types[] = $owner->getTypeOfField($of) ?? ParameterType::STRING;
        }

        // The schema with the name, because a name alone is a different table on a
        // connection whose search path holds one of the same name: Doctrine's own quote
        // strategy qualifies it, and a query that does not would count another table's
        // rows and call a refusal an emptying.
        $qualified = \is_string($schema) && $schema !== ''
            ? $platform->quoteIdentifier($schema).'.'.$platform->quoteIdentifier($table)
            : $platform->quoteIdentifier($table);

        // COUNT rather than a LIMIT, which every platform spells differently: one owner's
        // rows are few, and this runs where an emptying is being recorded or judged.
        return ['SELECT COUNT(*) FROM '.$qualified.' WHERE '.implode(' AND ', $where), $values, $types];
    }

    /**
     * One entry of a Doctrine mapping, whichever of its two shapes it has.
     *
     * An array on ORM 2, an object over the same keys on ORM 3. Null for anything that is
     * neither, and for a key that is not there — which is how an association with no join
     * table answers, and is the only answer this needs to tell apart.
     */
    public static function entry(mixed $mapping, string $key): mixed
    {
        if (\is_array($mapping)) {
            return $mapping[$key] ?? null;
        }

        return $mapping instanceof \ArrayAccess && $mapping->offsetExists($key) ? $mapping[$key] : null;
    }
}
