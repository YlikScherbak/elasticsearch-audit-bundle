<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * The questions asked of an owning ManyToMany's join table, built from its mapping: which
 * targets an owner's rows hold, and which owners hold a target -- with everything those owners
 * hold, in the same statement.
 *
 * Keys, and never entities. What is read is the join table's own columns through the
 * connection, so no target is loaded into the application's identity map and no postLoad of
 * the application's runs on the bundle's account; and no filter of the application's hides a
 * row, since the statements it has to account for take the rows whatever a filter would show.
 *
 * Apart from where they are run for the reason {@see CollectionRowsQuery} is: a mapping is a
 * parameter here, and the shapes no fixture has -- a schema, quoted names, a key of several
 * columns -- are a test's to hand over. Null when the mapping cannot say: the caller then knows
 * nothing, and says so.
 *
 * @internal
 */
final class JoinRowsQuery
{
    /**
     * What one owner's rows hold: its targets' keys, by the columns they reference.
     *
     * @param mixed                $mapping  the association's mapping, owning side
     * @param array<string, mixed> $ownerKey the owner's key as the join rows carry it: column
     *                                       of the owner's => database value
     *
     * @return array{sql: string, params: list<mixed>, targets: list<string>}|null the targets'
     *         referenced columns, in the order the statement selects them
     */
    public static function linksOf(AbstractPlatform $platform, mixed $mapping, array $ownerKey): ?array
    {
        $table = self::table($platform, $mapping);
        $owners = self::columns(CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'joinColumns'));
        $targets = self::columns(CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'inverseJoinColumns'));

        if ($table === null || $owners === null || $targets === null) {
            return null;
        }

        $where = [];
        $params = [];

        foreach ($owners as [$name, $referenced, $column]) {
            if (!\array_key_exists($referenced, $ownerKey)) {
                return null; // joined by a column that is not the owner's key
            }

            $where[] = self::named($platform, $name, $column).' = ?';
            $params[] = $ownerKey[$referenced];
        }

        return [
            'sql' => sprintf(
                'SELECT %s FROM %s WHERE %s',
                implode(', ', array_map(static fn (array $c): string => self::named($platform, $c[0], $c[2]), $targets)),
                $table,
                implode(' AND ', $where),
            ),
            'params' => $params,
            'targets' => array_map(static fn (array $c): string => $c[1], $targets),
        ];
    }

    /**
     * Every owner that holds any of some targets, each with everything it holds: one statement,
     * so that a target going is read as the move of each whole list it was in -- `[a, b] -> [b]`,
     * and not `[a] -> []` -- and a hundred going in one flush are one question, not a hundred.
     *
     * @param mixed                            $mapping    the association's mapping, owning side
     * @param non-empty-list<array<string, mixed>> $targetKeys each target's key as the join rows carry it
     *
     * @return array{sql: string, params: list<mixed>, owners: list<string>, targets: list<string>}|null
     *         the owners' then the targets' referenced columns, in the order the statement
     *         selects them
     */
    public static function holdersOf(AbstractPlatform $platform, mixed $mapping, array $targetKeys): ?array
    {
        $table = self::table($platform, $mapping);
        $owners = self::columns(CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'joinColumns'));
        $targets = self::columns(CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'inverseJoinColumns'));

        if ($table === null || $owners === null || $targets === null) {
            return null;
        }

        $same = [];
        $params = [];

        foreach ($owners as [$name, , $column]) {
            $same[] = 'h.'.self::named($platform, $name, $column).' = j.'.self::named($platform, $name, $column);
        }

        // Which targets: `h.t IN (?, ?)` for a key of one column, and a conjunction per target
        // otherwise, since a row value list is not spelled the same everywhere.
        $any = [];

        foreach ($targetKeys as $targetKey) {
            $one = [];

            foreach ($targets as [$name, $referenced, $column]) {
                if (!\array_key_exists($referenced, $targetKey)) {
                    return null;
                }

                $one[] = 'h.'.self::named($platform, $name, $column).' = ?';
                $params[] = $targetKey[$referenced];
            }

            $any[] = $one;
        }

        $same[] = \count($targets) === 1
            ? 'h.'.self::named($platform, $targets[0][0], $targets[0][2]).(\count($any) === 1 ? ' = ?' : ' IN ('.implode(', ', array_fill(0, \count($any), '?')).')')
            : (\count($any) === 1 ? implode(' AND ', $any[0]) : '('.implode(' OR ', array_map(static fn (array $one): string => '('.implode(' AND ', $one).')', $any)).')');

        return [
            'sql' => sprintf(
                'SELECT %s FROM %s j WHERE EXISTS (SELECT 1 FROM %s h WHERE %s)',
                implode(', ', array_map(static fn (array $c): string => 'j.'.self::named($platform, $c[0], $c[2]), [...$owners, ...$targets])),
                $table,
                $table,
                implode(' AND ', $same),
            ),
            'params' => $params,
            'owners' => array_map(static fn (array $c): string => $c[1], $owners),
            'targets' => array_map(static fn (array $c): string => $c[1], $targets),
        ];
    }

    /**
     * Which owners hold one target: what is asked right after the target's DELETE, to tell
     * whether the database took its rows with it ({@see LookRightAfter}). One placeholder for
     * each of the target's key columns, in the order given.
     *
     * @param mixed        $mapping the association's mapping, owning side
     * @param list<string> $columns the target's key columns, in the order the look binds them
     *
     * @return array{sql: string, owners: list<string>}|null the owners' referenced columns, in the order the statement selects them
     */
    public static function ownersHolding(AbstractPlatform $platform, mixed $mapping, array $columns): ?array
    {
        $table = self::table($platform, $mapping);
        $owners = self::columns(CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'joinColumns'));
        $targets = self::columns(CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'inverseJoinColumns'));

        if ($table === null || $owners === null || $targets === null) {
            return null;
        }

        $byReferenced = [];

        foreach ($targets as [$name, $referenced, $column]) {
            $byReferenced[$referenced] = self::named($platform, $name, $column).' = ?';
        }

        $where = [];

        foreach ($columns as $column) {
            if (!isset($byReferenced[$column])) {
                return null;
            }

            $where[] = $byReferenced[$column];
        }

        if (\count($where) !== \count($byReferenced)) {
            return null;
        }

        return [
            'sql' => sprintf(
                'SELECT %s FROM %s WHERE %s',
                implode(', ', array_map(static fn (array $c): string => self::named($platform, $c[0], $c[2]), $owners)),
                $table,
                implode(' AND ', $where),
            ),
            'owners' => array_map(static fn (array $c): string => $c[1], $owners),
        ];
    }

    /**
     * The join table's name as Doctrine's quote strategy writes it, with its schema: a name
     * alone is another table on a connection whose search path holds one of the same name.
     */
    private static function table(AbstractPlatform $platform, mixed $mapping): ?string
    {
        $joinTable = CollectionRowsQuery::entry($mapping, 'joinTable');
        $name = CollectionRowsQuery::entry($joinTable, 'name');
        $schema = CollectionRowsQuery::entry($joinTable, 'schema');

        if (!\is_string($name) || $name === '') {
            return null;
        }

        return \is_string($schema) && $schema !== ''
            ? self::named($platform, $schema, $joinTable).'.'.self::named($platform, $name, $joinTable)
            : self::named($platform, $name, $joinTable);
    }

    /**
     * @return list<array{0: string, 1: string, 2: mixed}>|null name, the column it references, and the column's mapping
     */
    private static function columns(mixed $columns): ?array
    {
        if (!\is_array($columns) || $columns === []) {
            return null;
        }

        $read = [];

        foreach ($columns as $column) {
            $name = CollectionRowsQuery::entry($column, 'name');
            $referenced = CollectionRowsQuery::entry($column, 'referencedColumnName');

            if (!\is_string($name) || !\is_string($referenced)) {
                return null;
            }

            $read[] = [$name, $referenced, $column];
        }

        return $read;
    }

    /** Quoted only where the mapping says quoted, as Doctrine writes it ({@see CollectionRowsQuery}). */
    private static function named(AbstractPlatform $platform, string $name, mixed $mapping): string
    {
        return CollectionRowsQuery::entry($mapping, 'quoted') !== null ? $platform->quoteIdentifier($name) : $name;
    }
}
