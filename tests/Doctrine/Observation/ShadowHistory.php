<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowBinding;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * The history the connection's log would give, built in the tests to be compared with the
 * truth before anything in the listener is decided from it.
 *
 * TEST-ONLY, and a prototype of what phase 3 will make the listener's own. It starts from what
 * the rows held and replays every committed statement over it: an UPDATE's old side is the
 * row's value before it, its new side what the statement set -- so S1b is 1 -> 7 and S1a both
 * 1 -> 2 and 2 -> 5, and Doctrine's change sets are not read at all.
 *
 * **Where the rows it starts from come from** is the one thing given to it, and two sources are
 * compared: the rows themselves, read before the scenario, which is what proves the log is
 * enough; and what Doctrine remembered of each row at the first preFlush of the operation, or
 * at postLoad for a row first loaded inside it, which is what a listener could have in
 * production. Not onFlush: computeChangeSets() has written the planned values over Doctrine's
 * memory by then.
 *
 * **What it reads, and what it does not.** Changes inside the elements of a tracked collection,
 * elements arriving and leaving, an owner's collection emptied, and an audited field of the
 * owner changing. A representer is called on a copy of the element made from the row, so it
 * sees the row rather than the object -- which holds for a representer that reads the
 * element's own columns, as getSku does, and is not promised for one that reads an association.
 * Anything else a committed statement did is not guessed at: it is listed as unsure, and a
 * comparison with anything unsure fails.
 *
 * Voided statements are skipped whole, and so is what could not be read in them: a rollback
 * takes the doubt with it as it takes the change.
 */
final class ShadowHistory
{
    /** @var array<class-string, array<string, array<string, mixed>>> root class => key => column => database value */
    private array $rows = [];

    /** @var array<class-string, array<string, true>> rows a committed DELETE took */
    private array $gone = [];

    /** @var list<string> */
    private array $facts = [];

    /** @var list<int|null> the flush each fact's statement belongs to, beside it */
    private array $owners = [];

    private ?StatementLog $log = null;

    /** @var list<string> */
    private array $unsure = [];

    /** The statement being replayed. */
    private int $at = 0;

    /** @var array<class-string, array<string, int>> where the log stood when each starting row was taken */
    private array $takenAt = [];

    /**
     * @param array<class-string, array<string, array<string, mixed>>> $rows
     * @param array<class-string, array<string, int>>                 $takenAt
     */
    private function __construct(private readonly EntityManagerInterface $em, array $rows, array $takenAt = [])
    {
        $this->rows = $rows;
        $this->takenAt = $takenAt;
    }

    /**
     * Starting from the rows as they are now.
     *
     * @param list<class-string> $classes
     */
    public static function fromTheRows(EntityManagerInterface $em, array $classes): self
    {
        $rows = [];

        foreach ($classes as $class) {
            $metadata = $em->getClassMetadata($class);

            foreach ($em->getConnection()->fetchAllAssociative('SELECT * FROM '.$metadata->getTableName()) as $row) {
                $rows[$metadata->rootEntityName][self::keyOf($metadata, $row)] = $row;
            }
        }

        return new self($em, $rows);
    }

    /**
     * Starting from what Doctrine remembered of each row when the operation began.
     *
     * @param array<class-string, array<string, array<string, mixed>>> $remembered
     * @param array<class-string, array<string, int>>                 $copiedAt where the log stood
     *                                                                           when each was copied
     */
    public static function fromWhatWasRemembered(EntityManagerInterface $em, array $remembered, array $copiedAt = []): self
    {
        return new self($em, $remembered, $copiedAt);
    }

    /**
     * Replays the log from a position.
     *
     * @param array<class-string, list<object>> $persisted every entity postPersist announced, by
     *                                                     class and in order: a row whose key
     *                                                     the database handed out is bound to
     *                                                     its INSERT by that order
     *
     * @return array{facts: list<string>, unsure: list<string>, owners: list<int|null>}
     */
    public function replay(StatementLog $log, int $from, array $persisted): array
    {
        $this->log = $log;
        $insertsWithoutKey = [];

        for ($at = $from + 1; $at <= $log->position(); ++$at) {
            $this->at = $at;
            $statement = $log->statement($at);

            if ($statement === null) {
                continue;
            }

            $shape = StatementShape::read($statement['sql']);
            $binding = $shape === null ? null : RowBinding::of($this->em, $shape, $statement['params']);

            // Counted whatever became of it: postPersist is announced for an INSERT the
            // transaction later rolls back, and the order has to line up with every one.
            $keyless = $shape !== null && $binding !== null && $shape->kind === StatementShape::INSERT && $binding->kind === RowBinding::ROW && $binding->key === null;
            $nth = null;

            if ($keyless) {
                $nth = $insertsWithoutKey[(string) $binding->class] = ($insertsWithoutKey[(string) $binding->class] ?? -1) + 1;
            }

            if ($log->fate($at) !== StatementLog::COMMITTED) {
                continue;
            }

            if ($shape === null || $binding === null) {
                $this->unsure[] = 'not read: '.$statement['sql'];

                continue;
            }

            if ($binding->kind === RowBinding::UNBOUND) {
                $this->unsure[] = 'not bound: '.$statement['sql'].' -- '.$binding->reason;

                continue;
            }

            if ($binding->kind === RowBinding::ROWS_OF_OWNER) {
                $this->emptied($shape, $binding, $statement['affected']);

                continue;
            }

            if ($binding->kind !== RowBinding::ROW || $binding->class === null) {
                $this->unsure[] = 'not replayed: '.$binding->kind.' '.$statement['sql'];

                continue;
            }

            $metadata = $this->em->getClassMetadata($binding->class);
            $table = $this->em->getClassMetadata($binding->class)->getTableName() === $shape->table
                ? $metadata
                : $this->tableOwner($binding->class, $shape->table);

            // A starting row taken after this statement ran is no account of the row before it.
            if ($binding->key !== null) {
                $this->forgetWhatWasTakenAfter($binding->class, self::keyOf($metadata, $binding->key), $at);
            }

            match ($shape->kind) {
                StatementShape::INSERT => $this->inserted($table, $shape, $statement['params'], $binding->key, $keyless ? ($persisted[$binding->class][$nth ?? 0] ?? null) : null),
                StatementShape::UPDATE => $this->updated($table, $shape, $statement['params'], (array) $binding->key),
                default => $this->deleted($table, (array) $binding->key, $statement['affected']),
            };
        }

        return ['facts' => $this->facts, 'unsure' => $this->unsure, 'owners' => $this->owners];
    }

    /** A fact, and the flush the statement that is its evidence belongs to. */
    private function said(string $fact): void
    {
        $this->facts[] = $fact;
        $this->owners[] = $this->log?->ownerOf($this->at);
    }

    private function forgetWhatWasTakenAfter(string $root, string $id, int $at): void
    {
        if (isset($this->takenAt[$root][$id]) && $this->takenAt[$root][$id] >= $at) {
            unset($this->rows[$root][$id], $this->takenAt[$root][$id]);
        }
    }

    /**
     * @param ClassMetadata<object>   $metadata
     * @param array<array-key, mixed> $params
     * @param array<string, mixed>|null $key
     */
    private function inserted(ClassMetadata $metadata, StatementShape $shape, array $params, ?array $key, ?object $persisted): void
    {
        $row = [];

        foreach ($shape->assigned as $column => $parameter) {
            $row[$column] = $parameter === null ? null : $params[$parameter] ?? null;
        }

        if ($key === null) {
            if ($persisted === null) {
                $this->unsure[] = 'an INSERT into '.$shape->table.' with no postPersist to take its key from';

                return;
            }

            foreach ($metadata->getIdentifierValues($persisted) as $field => $value) {
                $row[$metadata->getColumnName($field)] = $value;
            }

            $this->forgetWhatWasTakenAfter($metadata->rootEntityName, self::keyOf($metadata, $row), $this->at);
        }

        $root = $metadata->rootEntityName;
        $id = self::keyOf($metadata, $row);

        // A row deleted and inserted again under its key starts over; a row already there --
        // the root table's, when a JOINED child writes its own -- is added to, the new columns
        // winning, and it arrived once.
        $there = isset($this->gone[$root][$id]) ? [] : ($this->rows[$root][$id] ?? []);
        $this->rows[$root][$id] = $row + $there;
        unset($this->gone[$root][$id]);

        [$owner, $collection] = $this->ownerOf($metadata, $this->rows[$root][$id]);

        if ($owner !== null && $collection !== null && $there === []) {
            $this->said(sprintf('%s %s %s.%s: null -> %s', $owner[0], $owner[1], $collection, $id, json_encode($this->represent($metadata, $this->rows[$root][$id], $owner[2], $collection))));
        }
    }

    /**
     * @param ClassMetadata<object>   $metadata
     * @param array<array-key, mixed> $params
     * @param array<string, mixed>    $key
     */
    private function updated(ClassMetadata $metadata, StatementShape $shape, array $params, array $key): void
    {
        $root = $metadata->rootEntityName;
        $id = self::keyFrom($metadata, $key);

        if (isset($this->gone[$root][$id])) {
            return; // an UPDATE of a row a committed DELETE had taken reached nothing
        }

        if (!isset($this->rows[$root][$id])) {
            $this->unsure[] = 'an UPDATE of '.$root.' '.$id.', a row nothing said was there';

            return;
        }

        $before = $this->rows[$root][$id];
        [$owner, $collection] = $this->ownerOf($metadata, $before);

        foreach ($shape->assigned as $column => $parameter) {
            if ($parameter === null) {
                $this->rows[$root][$id][$column] = null; // an expression, as a version's; not followed

                continue;
            }

            $new = $params[$parameter] ?? null;
            $field = $metadata->getFieldForColumn($column);
            $old = $before[$column] ?? null;

            $this->rows[$root][$id][$column] = $new;

            if ($this->same($metadata, $field, $old, $new)) {
                continue;
            }

            if ($owner !== null && $collection !== null && $metadata->hasField($field)) {
                $this->said(sprintf(
                    '%s %s %s.%s.%s: %s -> %s',
                    $owner[0],
                    $owner[1],
                    $collection,
                    $id,
                    $field,
                    json_encode($this->php($metadata, $field, $old)),
                    json_encode($this->php($metadata, $field, $new)),
                ));

                continue;
            }

            $audited = (new AuditMetadataFactory())->for($metadata->newInstance());

            if ($audited !== null && \array_key_exists($field, $audited->fields) && $metadata->hasField($field)) {
                $this->said(sprintf('%s %s %s: %s -> %s', $audited->objectType, $id, $field, json_encode($this->php($metadata, $field, $old)), json_encode($this->php($metadata, $field, $new))));

                continue;
            }

            if ($owner === null && $audited === null) {
                continue; // a column of nothing audited
            }

            $this->unsure[] = 'an UPDATE of '.$root.'.'.$column.' this does not describe';
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $key
     */
    private function deleted(ClassMetadata $metadata, array $key, int|string|null $affected): void
    {
        $root = $metadata->rootEntityName;
        $id = self::keyFrom($metadata, $key);

        // A DELETE counts the rows it took on every engine; none means there was no row.
        if ((int) $affected === 0) {
            return;
        }

        if (!isset($this->rows[$root][$id])) {
            $this->unsure[] = 'a DELETE of '.$root.' '.$id.', a row nothing said was there';

            return;
        }

        [$owner, $collection] = $this->ownerOf($metadata, $this->rows[$root][$id]);

        if ($owner !== null && $collection !== null) {
            $this->said(sprintf('%s %s %s.%s: %s -> null', $owner[0], $owner[1], $collection, $id, json_encode($this->represent($metadata, $this->rows[$root][$id], $owner[2], $collection))));
        }

        $this->gone[$root][$id] = true;
    }

    private function emptied(StatementShape $shape, RowBinding $binding, int|string|null $affected): void
    {
        $owner = $this->em->getClassMetadata((string) $binding->class);
        $elements = null;

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $candidate) {
            if ($candidate instanceof ClassMetadata && $candidate->getTableName() === $shape->table) {
                $elements = $candidate;
            }
        }

        if ($elements === null || $binding->association === null) {
            $this->unsure[] = 'an emptying of '.$shape->table.' this cannot follow';

            return;
        }

        $column = (string) array_key_first($shape->where);
        $value = array_values((array) $binding->key)[0] ?? null;
        $root = $elements->rootEntityName;
        $held = [];

        foreach ($this->rows[$root] ?? [] as $id => $row) {
            if (!isset($this->gone[$root][$id]) && (string) ($row[$column] ?? '') === (string) $value) {
                $held[$id] = $row;
            }
        }

        // What the rows held is what went -- if the count says so. A difference is the rows
        // having changed in a way this did not follow.
        if (\count($held) !== (int) $affected) {
            $this->unsure[] = sprintf('an emptying of %s took %s rows where %d were known', $shape->table, (string) $affected, \count($held));

            return;
        }

        [$ownerType, $ownerId, $collection] = $this->collectionOf($owner, $elements, $binding->association, $value);

        if ($collection === null || $held === []) {
            foreach (array_keys($held) as $id) {
                $this->gone[$root][$id] = true;
            }

            return;
        }

        ksort($held);
        $shown = [];

        foreach ($held as $id => $row) {
            $shown[] = $this->represent($elements, $row, $owner, $collection);
            $this->gone[$root][$id] = true;
        }

        $this->said(sprintf('%s %s %s: %s -> []', $ownerType, $ownerId, $collection, json_encode($shown)));
    }

    /**
     * The owner a row's foreign key names, and the tracked collection it is an element of.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     *
     * @return array{0: array{0: string, 1: string, 2: ClassMetadata<object>}|null, 1: string|null}
     */
    private function ownerOf(ClassMetadata $metadata, array $row): array
    {
        foreach ($metadata->getAssociationNames() as $association) {
            if (!$metadata->isSingleValuedAssociation($association)) {
                continue;
            }

            $columns = CollectionRowsQuery::entry($metadata->getAssociationMapping($association), 'joinColumns');

            if (!\is_array($columns) || \count($columns) !== 1) {
                continue;
            }

            $column = CollectionRowsQuery::entry(reset($columns), 'name');

            if (!\is_string($column) || ($row[$column] ?? null) === null) {
                continue;
            }

            $owner = $this->em->getClassMetadata($metadata->getAssociationTargetClass($association));
            [$type, $id, $collection] = $this->collectionOf($owner, $metadata, $association, $row[$column]);

            if ($collection !== null) {
                return [[$type, $id, $owner], $collection];
            }
        }

        return [null, null];
    }

    /**
     * @param ClassMetadata<object> $owner
     * @param ClassMetadata<object> $elements
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function collectionOf(ClassMetadata $owner, ClassMetadata $elements, string $association, mixed $key): array
    {
        $audited = (new AuditMetadataFactory())->for($owner->newInstance());

        if ($audited === null) {
            return ['', '', null];
        }

        foreach ($owner->getAssociationNames() as $collection) {
            if ($owner->isCollectionValuedAssociation($collection)
                && $owner->getAssociationMappedByTargetField($collection) === $association
                && $owner->getAssociationTargetClass($collection) === $elements->rootEntityName
                && \array_key_exists($collection, $audited->fields)
            ) {
                return [$audited->objectType, (string) $key, $collection];
            }
        }

        return [$audited->objectType, (string) $key, null];
    }

    /**
     * What the owner's collection shows of an element, from the row: the representer is
     * given a copy made from it, not the object Doctrine holds.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     * @param ClassMetadata<object> $owner
     */
    private function represent(ClassMetadata $metadata, array $row, ClassMetadata $owner, string $collection): mixed
    {
        $copy = $metadata->newInstance();

        foreach ($metadata->getFieldNames() as $field) {
            $metadata->setFieldValue($copy, $field, $this->php($metadata, $field, $row[$metadata->getColumnName($field)] ?? null));
        }

        $represent = (new AuditMetadataFactory())->for($owner->newInstance())?->fields[$collection] ?? null;

        return $represent === null ? null : $represent($copy);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function tableOwner(string $root, string $table): ClassMetadata
    {
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $candidate) {
            if ($candidate instanceof ClassMetadata && $candidate->rootEntityName === $root && $candidate->getTableName() === $table) {
                return $candidate;
            }
        }

        return $this->em->getClassMetadata($root);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function php(ClassMetadata $metadata, string $field, mixed $value): mixed
    {
        if ($value === null || !$metadata->hasField($field)) {
            return $value;
        }

        $type = $metadata->getTypeOfField($field);

        return \is_string($type) ? Type::getType($type)->convertToPHPValue($value, $this->em->getConnection()->getDatabasePlatform()) : $value;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function same(ClassMetadata $metadata, string $field, mixed $old, mixed $new): bool
    {
        return $this->php($metadata, $field, $old) == $this->php($metadata, $field, $new);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     */
    public static function keyOf(ClassMetadata $metadata, array $row): string
    {
        $parts = [];

        foreach ($metadata->getIdentifierColumnNames() as $column) {
            $parts[] = (string) ($row[$column] ?? '');
        }

        return implode('|', $parts);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $key
     */
    private static function keyFrom(ClassMetadata $metadata, array $key): string
    {
        return self::keyOf($metadata, $key);
    }
}
