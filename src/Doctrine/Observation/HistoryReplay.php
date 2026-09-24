<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * The history the connection's log gives: what the rows held, and every statement that stayed
 * done replayed over it.
 *
 * An UPDATE's old side is the row's value before it and its new side what the statement set,
 * so a line the nested flush wrote for the outer one is 1 -> 7 and a column written twice is
 * two facts, and Doctrine's change sets are not read at all. A DELETE that took no row is not
 * a departure. An UPDATE after a DELETE of the same row reached nothing -- unless the DELETE
 * was rolled back or the row inserted again under its key. An emptying takes the rows the
 * owner held, and a count that differs from what the DELETE took is doubt, not a guess.
 *
 * **What it reads.** Changes inside the elements of a tracked collection, elements arriving
 * and leaving, an owner's collection emptied, and an audited field of an owner. A representer
 * is called on a copy of the element made from the row -- which is the row, for a representer
 * that reads the element's own columns; one that reads an association is not promised
 * anything. What a statement did that this does not read, or a statement that could not be
 * read or bound, is doubt, listed with its reason: never a guess and never silence.
 *
 * Statements the log voided are skipped whole, doubt included: a rollback takes the doubt with
 * it as it takes the change. Which rows to start from is given to it; it neither reads the
 * database nor asks Doctrine.
 */
final class HistoryReplay
{
    /** @var array<string, array<string, array<string, mixed>>> root class => key => column => database value */
    private array $rows;

    /** @var array<string, array<string, true>> rows a DELETE that stayed done took */
    private array $gone = [];

    /** @var list<array{type: string, id: string, field: string, old: mixed, new: mixed, flush: int|null, at: int, element: array{owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string}|null}> */
    private array $facts = [];

    /** @var list<string> */
    private array $doubts = [];

    /** @var array<string, array<string, int>> where the log stood when each starting row was taken */
    private array $takenAt;

    private ?StatementLog $log = null;

    private int $at = 0;

    /** How far it has read, so that it can carry on from there. */
    private int $readTo = 0;

    /** How many statements it has read, for the tests that pin what reading costs. */
    private int $read = 0;

    /** @var array<string, int> by root class: how many INSERTs without a key it has read */
    private array $insertsWithoutKey = [];

    private readonly AuditMetadataFactory $audited;

    private readonly WatchedRows $watched;

    /**
     * Held weakly: a replay is kept from one flush to the next, and a manager the application
     * replaced -- what ManagerRegistry::resetManager() does after a failed flush -- has to be
     * free to go, with everything it manages. Kept alive here, it also told the listener that
     * a flush nothing could vouch for still had its manager.
     *
     * @var \WeakReference<EntityManagerInterface>
     */
    private readonly \WeakReference $em;

    /** @var list<\Throwable> what the application's representers threw while this was reading */
    private array $failures = [];

    /**
     * @param array<string, array<string, array<string, mixed>>> $rows    what the rows held, by root class and key
     * @param array<string, array<string, int>>                 $takenAt where the log stood when each was taken: a row
     *                                                                   taken after a statement ran is no account of it
     */
    public function __construct(EntityManagerInterface $em, array $rows, array $takenAt = [], ?AuditMetadataFactory $audited = null, ?WatchedRows $watched = null)
    {
        $this->em = \WeakReference::create($em);
        $this->rows = $rows;
        $this->takenAt = $takenAt;
        $this->audited = $audited ?? new AuditMetadataFactory();
        $this->watched = $watched ?? new WatchedRows($this->audited);
    }

    /**
     * Replays the log after a position, up to a position.
     *
     * @param array<string, list<array<string, mixed>>> $persisted the key of every row postPersist announced, by root class and in order: a row
     *                                               whose key the database handed out is bound to its INSERT by that order
     */
    public function replay(StatementLog $log, int $from, ?int $upTo = null, array $persisted = []): void
    {
        $this->log = $log;
        $upTo ??= $log->position();

        for ($at = max($from, $this->readTo) + 1; $at <= $upTo; ++$at) {
            $this->at = $at;
            $this->readTo = $at;
            ++$this->read;
            $statement = $log->statement($at);

            if ($statement === null) {
                continue;
            }

            $shape = StatementShape::read($statement['sql']);
            $binding = $shape === null ? null : RowBinding::of($this->em(), $shape, $statement['params']);

            // Counted whatever became of it: postPersist is announced for an INSERT the
            // transaction later rolls back, and the order has to line up with every one.
            $nth = null;

            if ($shape !== null && $binding !== null && $shape->kind === StatementShape::INSERT && $binding->kind === RowBinding::ROW && $binding->key === null && $binding->class !== null) {
                $nth = $this->insertsWithoutKey[$binding->class] = ($this->insertsWithoutKey[$binding->class] ?? -1) + 1;
            }

            if ($log->fate($at) === StatementLog::VOID) {
                continue;
            }

            if ($shape === null || $binding === null) {
                $this->doubts[] = 'not read: '.$statement['sql'];

                continue;
            }

            if ($binding->kind === RowBinding::UNBOUND) {
                $this->doubts[] = 'not bound: '.$statement['sql'].' -- '.$binding->reason;

                continue;
            }

            if ($binding->kind === RowBinding::ROWS_OF_OWNER) {
                $this->emptied($shape, $binding, $statement['affected']);

                continue;
            }

            if ($binding->kind !== RowBinding::ROW || $binding->class === null) {
                $this->doubts[] = 'not replayed: '.$binding->kind.' '.$statement['sql'];

                continue;
            }

            $table = $this->mappingOfTable($binding->class, $shape->table);

            if (!$this->watched->areWatched($this->em(), $table)) {
                continue; // a row no history is written about: neither a fact nor a doubt
            }

            if ($binding->key !== null) {
                $this->forgetWhatWasTakenAfter($binding->class, self::keyOf($table, $binding->key), $at);
            }

            match ($shape->kind) {
                StatementShape::INSERT => $this->inserted($table, $shape, $statement['params'], $binding->key, $nth === null ? null : ($persisted[$binding->class][$nth] ?? null)),
                StatementShape::UPDATE => $this->updated($table, $shape, $statement['params'], $binding->key ?? []),
                default => $this->deleted($table, $binding->key ?? [], $statement['affected']),
            };
        }
    }

    /** How far it has read. */
    public function readTo(): int
    {
        return $this->readTo;
    }

    /** How many statements it has read, since it was made. */
    public function read(): int
    {
        return $this->read;
    }

    /**
     * A row first remembered after reading began: known from now on, and an account only of
     * the statements after the point it was taken at.
     *
     * @param array<string, mixed> $row
     */
    public function learn(string $root, string $id, array $row, int $takenAt): void
    {
        if (!isset($this->rows[$root][$id]) && !isset($this->gone[$root][$id])) {
            $this->rows[$root][$id] = $row;
            $this->takenAt[$root][$id] = $takenAt;
        }
    }

    /**
     * @return list<array{type: string, id: string, field: string, old: mixed, new: mixed, flush: int|null, at: int, element: array{owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string}|null}>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    /** @return list<string> */
    public function doubts(): array
    {
        return $this->doubts;
    }

    /**
     * What the application's representers threw. Each is a doubt as well: the fact it was
     * for carries no value, and whoever writes the history reports these through its failure
     * policy -- a replay only reads, and has no policy of its own to follow.
     *
     * @return list<\Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    private function em(): EntityManagerInterface
    {
        return $this->em->get() ?? throw new \LogicException('The entity manager this replay reads the mappings of is gone.');
    }

    /**
     * The rows as the replayed statements left them, the ones a DELETE took gone.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function rows(): array
    {
        $rows = $this->rows;

        foreach ($this->gone as $root => $keys) {
            foreach (array_keys($keys) as $id) {
                unset($rows[$root][$id]);
            }
        }

        return $rows;
    }

    /**
     * The key a row is known by: its identifier columns' values, in the mapping's order.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<array-key, mixed> $row
     */
    public static function keyOf(ClassMetadata $metadata, array $row): string
    {
        $parts = [];

        foreach ($metadata->getIdentifierColumnNames() as $column) {
            $value = $row[$column] ?? '';
            $parts[] = \is_scalar($value) ? (string) $value : '';
        }

        return implode('|', $parts);
    }

    /**
     * @param array{owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string}|null $element
     *        for a change inside an element: which owner and collection, which row and which field -- what a writer needs to
     *        name it, where the strings above only describe it
     */
    private function said(string $type, string $id, string $field, mixed $old, mixed $new, ?array $element = null): void
    {
        $this->facts[] = ['type' => $type, 'id' => $id, 'field' => $field, 'old' => $old, 'new' => $new, 'flush' => $this->log?->ownerOf($this->at), 'at' => $this->at, 'element' => $element];
    }

    private function forgetWhatWasTakenAfter(string $root, string $id, int $at): void
    {
        if (isset($this->takenAt[$root][$id]) && $this->takenAt[$root][$id] >= $at) {
            unset($this->rows[$root][$id], $this->takenAt[$root][$id]);
        }
    }

    /**
     * @param ClassMetadata<object>     $metadata
     * @param array<array-key, mixed>   $params
     * @param array<string, mixed>|null $key
     * @param array<string, mixed>|null $persisted the key postPersist announced for this INSERT
     */
    private function inserted(ClassMetadata $metadata, StatementShape $shape, array $params, ?array $key, ?array $persisted): void
    {
        $row = [];

        foreach ($shape->assigned as $column => $parameter) {
            $row[$column] = $parameter === null ? null : $params[$parameter] ?? null;
        }

        if ($key === null) {
            if ($persisted === null || $persisted === []) {
                $this->doubts[] = 'an INSERT into '.$shape->table.' with no postPersist to take its key from';

                return;
            }

            foreach ($persisted as $column => $value) {
                $row[$column] = $value;
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

        foreach ($there === [] ? $this->ownersOf($metadata, $this->rows[$root][$id]) : [] as $owner) {
            $this->said($owner['type'], $owner['id'], $owner['collection'].'.'.$id, null, $this->represent($metadata, $this->rows[$root][$id], $owner['metadata'], $owner['collection']));
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
        $id = self::keyOf($metadata, $key);

        if (isset($this->gone[$root][$id])) {
            return; // an UPDATE of a row a DELETE that stayed done had taken reached nothing
        }

        if (!isset($this->rows[$root][$id])) {
            $this->doubts[] = 'an UPDATE of '.$root.' '.$id.', a row nothing said was there';

            return;
        }

        $before = $this->rows[$root][$id];
        $owners = $this->ownersOf($metadata, $before);
        $audited = $this->audited->for($metadata->newInstance());

        // An element this statement moves to another owner tells neither of them what else
        // changed in it: the one it left never held the new value and the one it joined never
        // held the old. Only through that association -- an owner it stays with on another is
        // told, which is what that collection is tracked for.
        $staying = [];

        foreach ($owners as $owner) {
            if (!\array_key_exists($owner['column'], $shape->assigned)) {
                $staying[] = $owner; // the statement does not touch this foreign key

                continue;
            }

            $parameter = $shape->assigned[$owner['column']];

            if ($parameter !== null && self::scalar($params[$parameter] ?? null) === self::scalar($before[$owner['column']] ?? null)) {
                $staying[] = $owner; // written, with the owner it already had
            }
        }

        foreach ($shape->assigned as $column => $parameter) {
            if ($parameter === null) {
                $this->rows[$root][$id][$column] = null; // an expression, as a version's; not followed

                continue;
            }

            $new = $params[$parameter] ?? null;
            $old = $before[$column] ?? null;
            $this->rows[$root][$id][$column] = $new;

            $field = $metadata->getFieldForColumn($column);

            if (!$metadata->hasField($field)) {
                if ($owners !== []) {
                    $this->doubts[] = 'an UPDATE of '.$root.'.'.$column.' this does not describe';
                }

                continue; // a foreign key of nothing tracked
            }

            $was = $this->php($metadata, $field, $old);
            $is = $this->php($metadata, $field, $new);

            if (self::same($was, $is)) {
                continue;
            }

            if ($owners !== []) {
                foreach ($staying as $owner) {
                    $this->said($owner['type'], $owner['id'], $owner['collection'].'.'.$id.'.'.$field, $was, $is, [
                        'owner' => $owner['metadata']->name,
                        'ownerKey' => $owner['key'],
                        'collection' => $owner['collection'],
                        'class' => $metadata->rootEntityName,
                        'key' => $key,
                        'field' => $field,
                    ]);
                }
            } elseif ($audited !== null && \array_key_exists($field, $audited->fields)) {
                $this->said($audited->objectType, $id, $field, $was, $is);
            }
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $key
     */
    private function deleted(ClassMetadata $metadata, array $key, int|string|null $affected): void
    {
        // A DELETE counts the rows it took on every engine; none means there was no row.
        if ((int) $affected === 0) {
            return;
        }

        $root = $metadata->rootEntityName;
        $id = self::keyOf($metadata, $key);

        if (!isset($this->rows[$root][$id])) {
            $this->doubts[] = 'a DELETE of '.$root.' '.$id.', a row nothing said was there';

            return;
        }

        foreach ($this->ownersOf($metadata, $this->rows[$root][$id]) as $owner) {
            $this->said($owner['type'], $owner['id'], $owner['collection'].'.'.$id, $this->represent($metadata, $this->rows[$root][$id], $owner['metadata'], $owner['collection']), null);
        }

        $this->gone[$root][$id] = true;
    }

    private function emptied(StatementShape $shape, RowBinding $binding, int|string|null $affected): void
    {
        $elements = null;

        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $candidate) {
            if ($candidate instanceof ClassMetadata && $candidate->getTableName() === $shape->table) {
                $elements = $candidate;
            }
        }

        if ($elements === null || $binding->association === null || $binding->class === null) {
            $this->doubts[] = 'an emptying of '.$shape->table.' this cannot follow';

            return;
        }

        $owner = $this->em()->getClassMetadata($binding->class);
        $column = (string) array_key_first($shape->where);
        $value = array_values($binding->key ?? [])[0] ?? null;
        $root = $elements->rootEntityName;
        $held = [];

        foreach (array_keys($this->rows[$root] ?? []) as $id) {
            // A row first remembered after this statement is no account of what the table
            // held when it ran -- the rule a single-row statement keeps too -- and counted
            // here it made the rows the DELETE took one more than it said, and nothing was
            // taken at all: an UPDATE of one of them afterwards, which reached no row, was
            // then read as a change.
            $this->forgetWhatWasTakenAfter($root, (string) $id, $this->at);
        }

        foreach ($this->rows[$root] ?? [] as $id => $row) {
            if (!isset($this->gone[$root][$id]) && self::scalar($row[$column] ?? null) === self::scalar($value)) {
                $held[$id] = $row;
            }
        }

        // What the rows held is what went -- if the count says so. A difference is the rows
        // having changed in a way this did not follow.
        if (\count($held) !== (int) $affected) {
            $this->doubts[] = sprintf('an emptying of %s took %s rows where %d were known', $shape->table, self::scalar($affected), \count($held));

            return;
        }

        $collection = $this->collectionOf($owner, $elements, $binding->association);
        ksort($held);
        $shown = [];

        foreach ($held as $id => $row) {
            if ($collection !== null) {
                $shown[] = $this->represent($elements, $row, $owner, $collection['field']);
            }

            $this->gone[$root][$id] = true;
        }

        if ($collection !== null && $held !== []) {
            $this->said($collection['type'], self::scalar($value), $collection['field'], $shown, []);
        }
    }

    /**
     * Every owner a row's foreign keys name, with the audited collection it is an element of
     * there: an element can belong to two -- a case on a pallet and in a depot.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     *
     * @return list<array{type: string, id: string, key: mixed, column: string, collection: string, metadata: ClassMetadata<object>}>
     */
    private function ownersOf(ClassMetadata $metadata, array $row): array
    {
        $owners = [];

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

            $owner = $this->em()->getClassMetadata($metadata->getAssociationTargetClass($association));
            $collection = $this->collectionOf($owner, $metadata, $association);

            if ($collection !== null) {
                $owners[] = ['type' => $collection['type'], 'id' => self::scalar($row[$column]), 'key' => $row[$column], 'column' => $column, 'collection' => $collection['field'], 'metadata' => $owner];
            }
        }

        return $owners;
    }

    /**
     * @param ClassMetadata<object> $owner
     * @param ClassMetadata<object> $elements
     *
     * @return array{type: string, field: string}|null
     */
    private function collectionOf(ClassMetadata $owner, ClassMetadata $elements, string $association): ?array
    {
        $audited = $this->audited->for($owner->newInstance());

        if ($audited === null) {
            return null;
        }

        foreach ($owner->getAssociationNames() as $field) {
            // Only the inverse side names the field it is mapped by; an owning ManyToMany of
            // the same class is another association, and asking it throws.
            if ($owner->isCollectionValuedAssociation($field)
                && $owner->isAssociationInverseSide($field)
                && $owner->getAssociationMappedByTargetField($field) === $association
                && $owner->getAssociationTargetClass($field) === $elements->rootEntityName
                && \array_key_exists($field, $audited->fields)
            ) {
                return ['type' => $audited->objectType, 'field' => $field];
            }
        }

        return null;
    }

    /**
     * What the owner's collection shows of an element, from the row: the representer is given
     * a copy made from it, not the object Doctrine holds.
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

        $represent = $this->audited->for($owner->newInstance())?->fields[$collection] ?? null;

        if ($represent === null) {
            return null;
        }

        // The application's code, run while the listener settles the rows in postFlush: what
        // it throws is not this replay's to raise.
        try {
            return $represent($copy);
        } catch (\Throwable $e) {
            $this->failures[] = $e;
            $this->doubts[] = sprintf('the representer of %s.%s threw %s', $owner->getName(), $collection, $e::class);

            return null;
        }
    }

    /**
     * The mapping whose table a statement wrote: the class itself, or the member of its JOINED
     * hierarchy the table belongs to.
     *
     * @return ClassMetadata<object>
     */
    private function mappingOfTable(string $root, string $table): ClassMetadata
    {
        $metadata = $this->em()->getClassMetadata($root);

        if ($metadata->getTableName() === $table) {
            return $metadata;
        }

        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $candidate) {
            if ($candidate instanceof ClassMetadata && $candidate->rootEntityName === $root && $candidate->getTableName() === $table) {
                return $candidate;
            }
        }

        return $metadata;
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

        return \is_string($type) ? Type::getType($type)->convertToPHPValue($value, $this->em()->getConnection()->getDatabasePlatform()) : $value;
    }

    /**
     * Whether two values of one column are the same value: both have come through the column's
     * type, so they are of one kind -- and a date is the same instant, not the same object.
     */
    private static function same(mixed $one, mixed $other): bool
    {
        if ($one instanceof \DateTimeInterface && $other instanceof \DateTimeInterface) {
            return $one->format('U.u e') === $other->format('U.u e');
        }

        return $one === $other;
    }

    private static function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
