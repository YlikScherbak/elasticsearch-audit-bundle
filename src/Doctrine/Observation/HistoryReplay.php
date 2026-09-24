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
    /** A fact about a field of an element. */
    public const FIELD = 'field';

    /** A fact about an element come to a collection or gone from it. */
    public const MEMBER = 'member';

    /** A fact about a collection emptied: what it held, and nothing. */
    public const EMPTIED = 'emptied';

    /** @var array<string, array<string, array<string, mixed>>> root class => key => column => database value */
    private array $rows;

    /** @var array<string, array<string, true>> rows a DELETE that stayed done took */
    private array $gone = [];

    /** @var array<string, array<string, int|null>> the flush whose DELETE took each of them */
    private array $goneIn = [];

    /** @var list<array{type: string, id: string, field: string, old: mixed, new: mixed, flush: int|null, at: int, element: array{kind: string, owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string|null}|null, failed: bool}> */
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

    /** @var list<array{at: int, failure: \Throwable}> the same, with where each was */
    private array $failuresAt = [];

    /** Whether the last representer this called threw: the fact it was for is marked, never guessed. */
    private bool $representFailed = false;

    /** @var list<array{at: int, class: string|null}> where each doubt was, and about which class: no value it held */
    private array $doubtsAt = [];

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
                // A statement that writes and could not be read is doubt; one that does not
                // write -- the schema's DDL, say -- is nothing a row history holds. Nor is one
                // that writes a table of the application's own, read or not: the same rule as
                // for a statement read and not bound, below, by the table it names.
                if (preg_match('~^\s*(?:INSERT\s+(?:INTO\s+)?|UPDATE\s+|DELETE\s+(?:FROM\s+)?)([`"\[]?[\w.]+[`"\]]?)?~i', $statement['sql'], $writes) === 1) {
                    $table = isset($writes[1]) ? trim($writes[1], '`"[]') : null;
                    $watched = $table === null ? null : $this->watchedClassOf($table);

                    if ($table === null || $watched !== null || !$this->isAMappedTable($table)) {
                        $this->doubt('not read: '.$statement['sql'], $watched);
                    }
                }

                continue;
            }

            if ($binding->kind === RowBinding::UNBOUND) {
                $watched = $this->watchedClassOf($shape->table);

                // Doubt only about rows history is written about: a statement the application
                // ran on a table of its own is neither a fact nor a doubt.
                if ($watched !== null) {
                    $this->doubt('not bound: '.$statement['sql'].' -- '.$binding->reason, $watched);
                }

                continue;
            }

            if ($binding->kind === RowBinding::ROWS_OF_OWNER) {
                $this->emptied($shape, $binding, $statement['affected']);

                continue;
            }

            if ($binding->kind === RowBinding::JOIN_ROW || $binding->kind === RowBinding::JOIN_ROWS_OF_OWNER) {
                continue; // an owning collection's rows: recorded from its owner's change set
            }

            if ($binding->kind !== RowBinding::ROW || $binding->class === null) {
                $this->doubt('not replayed: '.$binding->kind.' '.$statement['sql'], $binding->class);

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
     * @return list<array{type: string, id: string, field: string, old: mixed, new: mixed, flush: int|null, at: int, element: array{kind: string, owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string|null}|null, failed: bool}>
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

    /**
     * What the representers threw for the statements after a position, for a writer to report
     * each once.
     *
     * @return list<\Throwable>
     */
    public function failuresAfter(int $at): array
    {
        return array_values(array_map(static fn (array $failure): \Throwable => $failure['failure'], array_filter($this->failuresAt, static fn (array $failure): bool => $failure['at'] > $at)));
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
     * The rows a DELETE that stayed done took, by root class.
     *
     * @return array<string, list<string>>
     */
    public function goneRows(): array
    {
        return array_map(static fn (array $keys): array => array_map('strval', array_keys($keys)), $this->gone);
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
     * @param array{kind: string, owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string|null}|null $element
     *        for a fact about a collection: what kind (a field of an element, an element come or gone, the collection
     *        emptied), which owner and collection, which row and which field -- what a writer needs to name it, where the
     *        strings above only describe it
     */
    private function said(string $type, string $id, string $field, mixed $old, mixed $new, ?array $element = null, bool $failed = false): void
    {
        $this->facts[] = ['type' => $type, 'id' => $id, 'field' => $field, 'old' => $old, 'new' => $new, 'flush' => $this->log?->ownerOf($this->at), 'at' => $this->at, 'element' => $element, 'failed' => $failed];
    }

    /**
     * An element come or gone, said with its representation -- marked when the representer threw.
     *
     * @param ClassMetadata<object> $metadata
     * @param array{type: string, id: string, key: mixed, column: string, collection: string, metadata: ClassMetadata<object>} $owner
     * @param array<string, mixed> $row
     * @param array<string, mixed> $key
     */
    private function member(ClassMetadata $metadata, array $owner, string $id, array $key, array $row, bool $arrived): void
    {
        $shown = $this->represent($metadata, $row, $owner['metadata'], $owner['collection']);

        $this->said($owner['type'], $owner['id'], $owner['collection'].'.'.$id, $arrived ? null : $shown, $arrived ? $shown : null, [
            'kind' => self::MEMBER,
            'owner' => $owner['metadata']->name,
            'ownerKey' => $owner['key'],
            'collection' => $owner['collection'],
            'class' => $metadata->rootEntityName,
            'key' => $key,
            'field' => null,
        ], $this->representFailed);
    }

    private function doubt(string $text, ?string $class): void
    {
        $this->doubts[] = $text;
        $this->doubtsAt[] = ['at' => $this->at, 'class' => $class];
    }

    /**
     * The doubts of the statements after a position: where, and about which class, and nothing a
     * statement carried -- for a writer to say what the history may be missing.
     *
     * @return list<array{at: int, class: string|null}>
     */
    public function doubtsAfter(int $at): array
    {
        return array_values(array_filter($this->doubtsAt, static fn (array $doubt): bool => $doubt['at'] > $at));
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
                $this->doubt('an INSERT into '.$shape->table.' with no postPersist to take its key from', $metadata->rootEntityName);

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
            $this->member($metadata, $owner, $id, self::keyColumnsOf($metadata, $this->rows[$root][$id]), $this->rows[$root][$id], arrived: true);
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
            $this->doubt('an UPDATE of '.$root.' '.$id.', a row nothing said was there', $root);

            return;
        }

        $before = $this->rows[$root][$id];
        $after = $before;

        foreach ($shape->assigned as $column => $parameter) {
            // An expression -- a version's -- is not followed: the column is not known after it.
            $after[$column] = $parameter === null ? null : $params[$parameter] ?? null;
        }

        $this->rows[$root][$id] = $after;

        $ownersBefore = self::byColumn($this->ownersOf($metadata, $before));
        $ownersAfter = self::byColumn($this->ownersOf($metadata, $after));
        $audited = $this->audited->for($metadata->newInstance());

        // A row this statement points at another owner left the one it had, as it was, and
        // arrived at the new one, as it is: the representer is given the row on each side of
        // the statement. Through that association only -- an owner the row keeps on another
        // hears nothing of a move.
        foreach (array_keys($ownersBefore + $ownersAfter) as $column) {
            $was = $ownersBefore[$column] ?? null;
            $is = $ownersAfter[$column] ?? null;

            if ($was !== null && $is !== null && $was['id'] === $is['id']) {
                continue; // the owner it already had
            }

            if ($was !== null) {
                $this->member($metadata, $was, $id, $key, $before, arrived: false);
            }

            if ($is !== null) {
                $this->member($metadata, $is, $id, $key, $after, arrived: true);
            }
        }

        foreach ($shape->assigned as $column => $parameter) {
            $field = $metadata->getFieldForColumn($column);

            // A foreign key is the move above, or an association of the element's own, which
            // is not something an element's history holds: a representer for it has nowhere
            // to be declared. An expression is not followed.
            if ($parameter === null || !$metadata->hasField($field)) {
                continue;
            }

            $was = $this->php($metadata, $field, $before[$column] ?? null);
            $is = $this->php($metadata, $field, $after[$column] ?? null);

            if (self::same($was, $is)) {
                continue;
            }

            // Told to the owners the row has once the statement ran: the one it arrived at
            // with its arrival -- the old side is the row's value, not a state of that owner's
            // -- and none, for a row the statement took out of every tracked collection,
            // which is as much nobody's history as any later change to it.
            foreach ($ownersAfter as $owner) {
                $this->said($owner['type'], $owner['id'], $owner['collection'].'.'.$id.'.'.$field, $was, $is, [
                    'kind' => self::FIELD,
                    'owner' => $owner['metadata']->name,
                    'ownerKey' => $owner['key'],
                    'collection' => $owner['collection'],
                    'class' => $metadata->rootEntityName,
                    'key' => $key,
                    'field' => $field,
                ]);
            }

            if ($ownersBefore === [] && $ownersAfter === [] && $audited !== null && \array_key_exists($field, $audited->fields)) {
                $this->said($audited->objectType, $id, $field, $was, $is);
            }
        }
    }

    /**
     * @param list<array{type: string, id: string, key: mixed, column: string, collection: string, metadata: ClassMetadata<object>}> $owners
     *
     * @return array<string, array{type: string, id: string, key: mixed, column: string, collection: string, metadata: ClassMetadata<object>}>
     */
    private static function byColumn(array $owners): array
    {
        $byColumn = [];

        foreach ($owners as $owner) {
            $byColumn[$owner['column']] = $owner;
        }

        return $byColumn;
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
            $this->doubt('a DELETE of '.$root.' '.$id.', a row nothing said was there', $root);

            return;
        }

        foreach ($this->ownersOf($metadata, $this->rows[$root][$id]) as $owner) {
            $this->member($metadata, $owner, $id, $key, $this->rows[$root][$id], arrived: false);
        }

        $this->gone[$root][$id] = true;
        $this->goneIn[$root][$id] = $this->log?->ownerOf($this->at);
    }

    /**
     * Whether a DELETE of this flush took the row: what an owner's collection went through in
     * the flush that removed the owner is its remove, not a second event.
     */
    public function removedIn(string $root, string $id, int $flush): bool
    {
        return isset($this->gone[$root][$id]) && ($this->goneIn[$root][$id] ?? null) === $flush;
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
            $this->doubt('an emptying of '.$shape->table.' this cannot follow', null);

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
            $this->doubt(sprintf('an emptying of %s took %s rows where %d were known', $shape->table, self::scalar($affected), \count($held)), $root);

            return;
        }

        $collection = $this->collectionOf($owner, $elements, $binding->association);
        ksort($held);
        $shown = [];
        $failed = false;

        foreach ($held as $id => $row) {
            if ($collection !== null) {
                $shown[] = $this->represent($elements, $row, $owner, $collection['field']);
                $failed = $failed || $this->representFailed;
            }

            $this->gone[$root][$id] = true;
        }

        if ($collection !== null && $held !== []) {
            $this->said($collection['type'], self::scalar($value), $collection['field'], $shown, [], [
                'kind' => self::EMPTIED,
                'owner' => $owner->name,
                'ownerKey' => $value,
                'collection' => $collection['field'],
                'class' => $root,
                'key' => [],
                'field' => null,
            ], $failed);
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
        $this->representFailed = false;
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
            $this->failuresAt[] = ['at' => $this->at, 'failure' => $e];
            $this->representFailed = true;
            $this->doubts[] = sprintf('the representer of %s.%s threw %s', $owner->getName(), $collection, $e::class);

            return null;
        }
    }

    /**
     * A row's key columns, as a binding names them.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     *
     * @return array<string, mixed>
     */
    private static function keyColumnsOf(ClassMetadata $metadata, array $row): array
    {
        $key = [];

        foreach ($metadata->getIdentifierColumnNames() as $column) {
            $key[$column] = $row[$column] ?? null;
        }

        return $key;
    }

    /**
     * Whether a table belongs to a mapped class. One that does and is not watched is the
     * application's own; one that does not may be a watched collection's join table, and a
     * write to it that could not be read stays doubt.
     */
    private function isAMappedTable(string $table): bool
    {
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $candidate) {
            if ($candidate instanceof ClassMetadata && $candidate->getTableName() === $table) {
                return true;
            }
        }

        return false;
    }

    /** The watched class a table holds the rows of, if any. */
    private function watchedClassOf(string $table): ?string
    {
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $candidate) {
            if ($candidate instanceof ClassMetadata && $candidate->getTableName() === $table && $this->watched->areWatched($this->em(), $candidate)) {
                return $candidate->rootEntityName;
            }
        }

        return null;
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
