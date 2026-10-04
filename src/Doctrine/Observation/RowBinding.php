<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Which rows a statement was about, in the mapping's terms: an entity's row by its key, the
 * rows an owner's collection held, or a join row -- or that it cannot be said.
 *
 * The statement's shape says which table and which columns; the mapping says what they
 * are. A row is named only when the WHERE is exact and names the key's columns and nothing
 * else -- a versioned entity's version column besides, which the persister always adds.
 * `WHERE id = ? AND status = ?` names the key and does not prove its row went, and
 * `WHERE sku = ?` names a row nothing here can identify; both are "not bound", with the
 * reason, because a statement bound wrongly would be believed.
 *
 * A JOINED hierarchy writes an entity as a statement per table and deletes it by the root's
 * table; every table of it binds to the root class, which is what an entity's identity is.
 * A DELETE by the one join column of an association is the persister emptying that owner's
 * collection, and it binds to the owner -- which rows it took is not in the statement, and
 * comes from what the listener read of the collection before it ran. One by the other join
 * column is a target going, taking its rows in every owner's collection: it binds to the
 * association, with the target as its element and no owner.
 */
final class RowBinding
{
    public const ROW = 'row';
    public const ROWS_OF_OWNER = 'rows of owner';
    public const JOIN_ROW = 'join row';
    public const JOIN_ROWS_OF_OWNER = 'join rows of owner';
    public const JOIN_ROWS_OF_TARGET = 'join rows of target';
    public const UNBOUND = 'unbound';

    /**
     * @param class-string|null         $class       the entity -- the root of its hierarchy -- or, for a join table and an emptying, the owner
     * @param array<string, mixed>|null $key         column => value; null for an INSERT whose key the database hands out
     * @param string|null               $association the owner's association, for a join table and an emptying
     * @param array<string, mixed>|null $element     the element's key, for a join row
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $class = null,
        public readonly ?array $key = null,
        public readonly ?string $association = null,
        public readonly ?array $element = null,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params the bound parameters, numbered from one
     */
    public static function of(EntityManagerInterface $em, StatementShape $shape, array $params): self
    {
        // Every mapping the manager knows, the root of each hierarchy among them: a statement of
        // a root's table is the root's, whichever of its classes comes first.
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!$metadata instanceof ClassMetadata) {
                continue;
            }

            if (self::tableOf($metadata) === $shape->table) {
                return self::entityRow($em, $metadata, $shape, $params);
            }

            foreach ($metadata->getAssociationNames() as $association) {
                $joinTable = CollectionRowsQuery::entry($metadata->getAssociationMapping($association), 'joinTable');

                if ($joinTable !== null && self::named($joinTable) === $shape->table) {
                    return self::joinRow($metadata, $association, $joinTable, $shape, $params);
                }
            }
        }

        return new self(self::UNBOUND, reason: 'no mapped table is called '.$shape->table);
    }

    /**
     * @param ClassMetadata<object>   $metadata
     * @param array<array-key, mixed> $params
     */
    private static function entityRow(EntityManagerInterface $em, ClassMetadata $metadata, StatementShape $shape, array $params): self
    {
        /** @var class-string $root */
        $root = $metadata->rootEntityName;
        $identifier = $metadata->getIdentifierColumnNames();

        if ($shape->kind === StatementShape::INSERT) {
            $key = [];

            foreach ($identifier as $column) {
                if (!\array_key_exists($column, $shape->assigned) || $shape->assigned[$column] === null) {
                    return new self(self::ROW, $root, null); // the database hands the key out
                }

                $key[$column] = $params[$shape->assigned[$column]] ?? null;
            }

            return new self(self::ROW, $root, $key);
        }

        if (!$shape->exact) {
            return new self(self::UNBOUND, reason: 'the WHERE is not a conjunction of column = ?');
        }

        $allowed = $identifier;

        if ($metadata->isVersioned && \is_string($metadata->versionField)) {
            $allowed[] = $metadata->getColumnName($metadata->versionField);
        }

        $named = array_keys($shape->where);

        if (self::sameColumns($named, $identifier) || self::sameColumns($named, $allowed)) {
            $key = [];

            foreach ($identifier as $column) {
                $key[$column] = $params[$shape->where[$column]] ?? null;
            }

            return new self(self::ROW, $root, $key);
        }

        // The persister empties an owner's collection by the one join column that points at
        // the owner; that is the only other WHERE it writes on an entity's table.
        if ($shape->kind === StatementShape::DELETE && \count($named) === 1) {
            foreach ($metadata->getAssociationNames() as $association) {
                if (!$metadata->isSingleValuedAssociation($association)) {
                    continue;
                }

                $columns = CollectionRowsQuery::entry($metadata->getAssociationMapping($association), 'joinColumns');

                if (!\is_array($columns) || \count($columns) !== 1 || CollectionRowsQuery::entry(reset($columns), 'name') !== $named[0]) {
                    continue;
                }

                $referenced = CollectionRowsQuery::entry(reset($columns), 'referencedColumnName');
                /** @var class-string $owner */
                $owner = $em->getClassMetadata($metadata->getAssociationTargetClass($association))->rootEntityName;

                return new self(
                    self::ROWS_OF_OWNER,
                    $owner,
                    [\is_string($referenced) ? $referenced : $named[0] => $params[$shape->where[$named[0]]] ?? null],
                    $association,
                );
            }
        }

        return new self(self::UNBOUND, reason: 'the WHERE names '.implode(', ', $named).', which is not the key of '.$root);
    }

    /**
     * @param ClassMetadata<object>   $owner
     * @param array<array-key, mixed> $params
     */
    private static function joinRow(ClassMetadata $owner, string $association, mixed $joinTable, StatementShape $shape, array $params): self
    {
        $ownerColumns = self::columnsOf(CollectionRowsQuery::entry($joinTable, 'joinColumns'));
        $elementColumns = self::columnsOf(CollectionRowsQuery::entry($joinTable, 'inverseJoinColumns'));
        /** @var class-string $root */
        $root = $owner->rootEntityName;

        if ($ownerColumns === [] || $elementColumns === []) {
            return new self(self::UNBOUND, reason: 'the join table of '.$root.'::'.$association.' has no columns this can read');
        }

        $values = $shape->kind === StatementShape::INSERT ? $shape->assigned : $shape->where;

        if ($shape->kind !== StatementShape::INSERT && !$shape->exact) {
            return new self(self::UNBOUND, reason: 'the WHERE is not a conjunction of column = ?');
        }

        $pick = static function (array $columns) use ($values, $params): ?array {
            $key = [];

            foreach ($columns as $column => $referenced) {
                if (!isset($values[$column])) {
                    return null;
                }

                $key[$referenced] = $params[$values[$column]] ?? null;
            }

            return $key;
        };

        $ownerKey = $pick($ownerColumns);
        $elementKey = $pick($elementColumns);
        $named = array_keys($values);

        if ($ownerKey !== null && $elementKey !== null && self::sameColumns($named, [...array_keys($ownerColumns), ...array_keys($elementColumns)])) {
            return new self(self::JOIN_ROW, $root, $ownerKey, $association, $elementKey);
        }

        if ($shape->kind === StatementShape::DELETE && $ownerKey !== null && self::sameColumns($named, array_keys($ownerColumns))) {
            return new self(self::JOIN_ROWS_OF_OWNER, $root, $ownerKey, $association);
        }

        if ($shape->kind === StatementShape::DELETE && $elementKey !== null && self::sameColumns($named, array_keys($elementColumns))) {
            return new self(self::JOIN_ROWS_OF_TARGET, $root, null, $association, $elementKey);
        }

        return new self(self::UNBOUND, reason: 'the join table of '.$root.'::'.$association.' is not named by the key of its rows');
    }

    /**
     * @return array<string, string> join column => the column it references
     */
    private static function columnsOf(mixed $columns): array
    {
        if (!\is_array($columns)) {
            return [];
        }

        $read = [];

        foreach ($columns as $column) {
            $name = CollectionRowsQuery::entry($column, 'name');
            $referenced = CollectionRowsQuery::entry($column, 'referencedColumnName');

            if (\is_string($name) && \is_string($referenced)) {
                $read[$name] = $referenced;
            }
        }

        return $read;
    }

    /**
     * A class's table as a statement names it once read: what a watch on that table is matched
     * against, so both say it one way.
     *
     * @param ClassMetadata<object> $metadata
     */
    public static function tableOf(ClassMetadata $metadata): string
    {
        return self::named($metadata->table);
    }

    private static function named(mixed $table): string
    {
        $name = CollectionRowsQuery::entry($table, 'name');
        $schema = CollectionRowsQuery::entry($table, 'schema');

        return (\is_string($schema) && $schema !== '' ? $schema.'.' : '').(\is_string($name) ? $name : '');
    }

    /**
     * @param list<string> $one
     * @param list<string> $other
     */
    private static function sameColumns(array $one, array $other): bool
    {
        sort($one);
        sort($other);

        return $one === $other;
    }
}
