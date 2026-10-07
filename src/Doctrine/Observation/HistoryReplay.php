<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\ChangeSetBuilder;
use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Exception\DeclarationMistake;
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

    /** @var array<string, array<string, int>> where the DELETE that took each of them is in the log */
    private array $goneAt = [];

    /** @var list<array{type: string, id: string, field: string, old: mixed, new: mixed, flush: int|null, at: int, element: array{kind: string, owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string|null}|null, failed: bool, ownerContext: array<string, mixed>}> */
    private array $facts = [];

    /**
     * What each statement did to a row of an audited class, one fact a statement: an INSERT, an
     * UPDATE or a DELETE, the row's class -- the most derived, for a hierarchy -- and key, the
     * statement's position in the log and the flush it belongs to, and the audited columns it
     * wrote, each from and to. A creation is a fact whether or not any column changed, and a
     * removal carries the row as it stood before it went.
     *
     * And its context: what the class's always-recorded fields held in the row once the
     * statement ran -- the row's, not the object's, which a postUpdate listener may have moved
     * since. Only the columns the replay knows the row by; a field it knows nothing of is left
     * out rather than said to be null.
     *
     * Whose flush each is, is asked of the log when the facts are read, not when the statement
     * was: a replay read in the middle of an operation -- to count what a flush left, say -- meets
     * statements the flush running them has not claimed yet, and an answer kept from then would
     * be nobody's for good.
     *
     * Kept as a list -- what it was, of which class, which row, the key's column names, their
     * values, where, its fields, and the row once it ran -- and given as the array its readers
     * read (eachRowFact()): twenty thousand facts of named arrays were a third more. The key's
     * column names are the table's, one array shared by every fact of it; each field's sides a
     * pair, old then new; whose flush it is, asked of the log when it is read, as it always was.
     *
     * A fact whose execution the history has been written from is let go of (letGoOf()): what
     * the readers of every fact from the first need of it stays -- what it was, of which row,
     * where, and the row once it ran -- and its key and fields go.
     *
     * @var list<array{0: string, 1: class-string, 2: string, 3: list<string>|null, 4: list<mixed>|null, 5: int, 6: array<string, array{0: mixed, 1: mixed}>|null, 7: array<string, mixed>}>
     */
    private array $rowFacts = [];

    /** @var array<string, list<string>> the key's column names, by class: one array for every fact of it */
    private array $keyColumns = [];

    /** @var array<string, array{owners?: array<string, list<array{at: int, kind: string, owner: array<string, mixed>, target: string|null, key: array<string, mixed>|null, affected: int|null}>>, targets?: list<array{at: int, target: string, key: array<string, mixed>, affected: int|null, by: string}>}> */
    private array $links = [];

    /**
     * What each watched row that moved while this read held after each statement that moved it,
     * by root class and key, the first entry what it held when it was taken: what shows a link's
     * target at the moment of the link ({@see copyAt()}) rather than as the rows stand once
     * everything ran. Only the rows that moved, and only since the rows were settled.
     *
     * @var array<string, array<string, list<array{0: int, 1: array<string, mixed>|null}>>>
     */
    private array $versions = [];

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

    /** @var list<array{at: int, classes: list<string>, like: list<string>, unnamed: bool}> where each doubt was, and about which classes: no value it held */
    private array $doubtsAt = [];

    /**
     * @param array<string, array<string, array<string, mixed>>> $rows              what the rows held, by root class and key
     * @param array<string, array<string, int>>                 $takenAt          where the log stood when each was taken: a row
     *                                                                            taken after a statement ran is no account of it
     * @param bool|null                                         $countsChangedRows whether an UPDATE's count is of the rows it changed
     *                                                                            rather than those it found; null, by the platform
     *                                                                            ({@see countsTheRowsItChanged()})
     */
    public function __construct(EntityManagerInterface $em, array $rows, array $takenAt = [], ?AuditMetadataFactory $audited = null, ?WatchedRows $watched = null, ?bool $countsChangedRows = null)
    {
        $this->countsChanged = $countsChangedRows;
        $this->em = \WeakReference::create($em);
        $this->rows = $rows;
        $this->takenAt = $takenAt;
        $this->audited = $audited ?? new AuditMetadataFactory();
        $this->watched = $watched ?? new WatchedRows($this->audited);
    }

    /**
     * Replays the log after a position, up to a position.
     */
    public function replay(StatementLog $log, int $from, ?int $upTo = null): void
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

            // Read without its comments; named in a doubt as it ran.
            $shape = $statement['read'] === null ? null : StatementShape::read($statement['read']);
            $binding = $shape === null ? null : RowBinding::of($this->em(), $shape, $statement['params']);

            if ($log->fate($at) === StatementLog::VOID) {
                continue;
            }

            if ($shape === null || $binding === null) {
                // A statement that writes and could not be read is doubt; one that does not
                // write -- the schema's DDL, say -- is nothing a row history holds. Nor is one
                // that writes a table of the application's own, read or not: the same rule as
                // for a statement read and not bound, below, by the table it names. A table it
                // cannot name is doubt: it may be any of them. So is one whose comments could not
                // be taken out ({@see ReadableSql}): what it does is not known, nor where.
                if ($statement['read'] === null) {
                    $this->doubt('not read: '.$statement['sql'], null);
                } else {
                    // The words of a write outside literals and quoted names, whatever the form --
                    // behind a common table expression, MySQL's REPLACE, SQLite's INSERT OR
                    // REPLACE, a MERGE: one doubt, of every watched class a name is of, and of any
                    // table besides if a name cannot be read -- which a name read after it does
                    // not take back. A name not the mapping's, that may be a watched table's, is
                    // doubt of it too.
                    $watched = [];
                    $like = [];
                    $unnamed = false;

                    foreach (StatementShape::writesItCannotRead($statement['read']) as $table) {
                        $class = $table === null ? null : $this->watchedClassOf($table->written());

                        if ($table === null) {
                            $unnamed = true;
                        } elseif ($class !== null) {
                            $watched[] = $class;
                        } else {
                            $like = [...$like, ...$this->watchedClassesItMayBe($table)];
                        }
                    }

                    if ($watched !== [] || $like !== [] || $unnamed) {
                        $this->doubtOf('not read: '.$statement['sql'], $watched, $like, $unnamed);
                    }
                }

                continue;
            }

            if ($binding->kind === RowBinding::UNBOUND) {
                $watched = $this->watchedClassOf($shape->table);

                // Doubt only about rows history is written about: a statement the application
                // ran on a table of its own is neither a fact nor a doubt. One named otherwise
                // than the mapping -- in another case, behind a schema -- that may be a watched
                // table is doubt of it, and is never bound or replayed as the mapping's.
                if ($watched !== null) {
                    $this->doubt('not bound: '.$statement['sql'].' -- '.$binding->reason, $watched);
                } elseif (($like = $this->watchedClassesItMayBe($shape->name)) !== []) {
                    $this->doubtOf('not bound: '.$statement['sql'].' -- '.$binding->reason, [], $like);
                }

                continue;
            }

            // A count not known -- none given, or one past what an int holds -- is never read as
            // none: the statement is taken as it was written, and is doubt.
            if ($statement['affected'] === null) {
                $watched = $this->watchedClassOf($shape->table);

                if ($watched !== null) {
                    $this->doubt('its count not known: '.$statement['sql'], $watched);
                }
            }

            if ($binding->kind === RowBinding::ROWS_OF_OWNER) {
                $this->emptied($shape, $binding, $statement['affected']);

                continue;
            }

            if ($binding->kind === RowBinding::JOIN_ROW || $binding->kind === RowBinding::JOIN_ROWS_OF_OWNER || $binding->kind === RowBinding::JOIN_ROWS_OF_TARGET) {
                // An owning collection's rows: kept as they ran, and told against what the owner's
                // rows held by {@see LinkFacts} -- which needs every statement of an owner before
                // it can say what any one of them did.
                $this->linkStatement($shape, $binding, $statement['affected']);

                continue;
            }

            if ($binding->kind !== RowBinding::ROW || $binding->class === null) {
                $this->doubt('not replayed: '.$binding->kind.' '.$statement['sql'], $binding->class);

                continue;
            }

            // A row going takes it out of every collection it was in, whether or not a history
            // is written about the row itself: by the database, for join columns that cascade,
            // with no statement of the join table's at all.
            if ($shape->kind === StatementShape::DELETE && $binding->key !== null && $statement['affected'] !== 0) {
                $this->aTargetWent($binding->class, $binding->key);
            }

            $table = $this->mappingOfTable($binding->class, $shape->table);

            if (!$this->watched->areWatched($this->em(), $table)) {
                continue; // a row no history is written about: neither a fact nor a doubt
            }

            if ($binding->key !== null) {
                $this->forgetWhatWasTakenAfter($binding->class, self::keyOf($table, $binding->key), $at);
            }

            match ($shape->kind) {
                StatementShape::INSERT => $this->inserted($table, $shape, $statement['params'], $binding->key, $statement['key']),
                StatementShape::UPDATE => $this->updated($table, $shape, $statement['params'], $binding->key ?? [], $statement['affected']),
                default => $this->deleted($table, $binding->key ?? [], $statement['affected']),
            };
        }
    }

    /**
     * The statements of watched join rows it has read, as they ran: by association, each
     * owner's own -- a link added or removed, or all of its links taken -- and the targets that
     * went, by a join table's statement or by their own row's DELETE.
     *
     * @return array<string, array{owners?: array<string, list<array{at: int, kind: string, owner: array<string, mixed>, target: string|null, key: array<string, mixed>|null, affected: int|null}>>, targets?: list<array{at: int, target: string, key: array<string, mixed>, affected: int|null, by: string}>}>
     */
    public function linkStatements(): array
    {
        return $this->links;
    }

    /**
     * Where the statements an account of an owner's links already holds are: the owner's own,
     * and every target of the association that went -- the ones read so far.
     *
     * @return list<int>
     */
    public function linkPositionsOf(string $association, string $owner): array
    {
        $at = [
            ...array_column($this->links[$association]['owners'][$owner] ?? [], 'at'),
            ...array_column($this->links[$association]['targets'] ?? [], 'at'),
        ];
        sort($at);

        return $at;
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
     * @return list<array{type: string, id: string, field: string, old: mixed, new: mixed, flush: int|null, at: int, element: array{kind: string, owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string|null}|null, failed: bool, ownerContext: array<string, mixed>}>
     */
    public function facts(): array
    {
        return iterator_to_array($this->eachFact(), false);
    }

    /**
     * The facts one at a time, each with the flush that ran it: what facts() lists, without a
     * copy of every one of them made at once -- a flush of twenty thousand rows made one per
     * reader.
     *
     * @return \Generator<int, array{type: string, id: string, field: string, old: mixed, new: mixed, flush: int|null, at: int, element: array{kind: string, owner: class-string, ownerKey: mixed, collection: string, class: class-string, key: array<string, mixed>, field: string|null}|null, failed: bool, ownerContext: array<string, mixed>}>
     */
    public function eachFact(): \Generator
    {
        foreach ($this->facts as $fact) {
            $fact['flush'] = $this->log?->ownerOf($fact['at']);

            yield $fact;
        }
    }

    /** @return list<string> */
    public function doubts(): array
    {
        return $this->doubts;
    }

    /**
     * What the application's representers threw, and the fields written as a stream or
     * changed from one ({@see doubtOfAStream()}). Each is a doubt as well: the fact it was
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
     * @return list<array{statement: string, class: class-string, id: string, key?: array<string, mixed>, at: int, flush: int|null, fields?: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>, released?: true}>
     */
    public function rowFacts(): array
    {
        return iterator_to_array($this->eachRowFact(), false);
    }

    /**
     * The facts of the rows one at a time, as eachFact() gives the facts. A released one
     * (letGoOf()) comes as what is kept of it: what it was, of which row, where, and the row once
     * it ran; its key and fields are not there to be read.
     *
     * @return \Generator<int, array{statement: string, class: class-string, id: string, key?: array<string, mixed>, at: int, flush: int|null, fields?: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>, released?: true}>
     */
    public function eachRowFact(): \Generator
    {
        foreach ($this->rowFacts as $index => $fact) {
            yield $index => $this->asRead($fact);
        }
    }

    /**
     * A fact as its readers read it, from the list it is kept as ($rowFacts).
     *
     * @param array{0: string, 1: class-string, 2: string, 3: list<string>|null, 4: list<mixed>|null, 5: int, 6: array<string, array{0: mixed, 1: mixed}>|null, 7: array<string, mixed>} $fact
     *
     * @return array{statement: string, class: class-string, id: string, key?: array<string, mixed>, at: int, flush: int|null, fields?: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>, released?: true}
     */
    private function asRead(array $fact): array
    {
        [$statement, $class, $id, $columns, $values, $at, $fields, $context] = $fact;

        if ($columns === null || $values === null || $fields === null) {
            return ['statement' => $statement, 'class' => $class, 'id' => $id, 'at' => $at, 'flush' => $this->log?->ownerOf($at), 'context' => $context, 'released' => true];
        }

        return [
            'statement' => $statement,
            'class' => $class,
            'id' => $id,
            'key' => array_combine($columns, $values),
            'at' => $at,
            'flush' => $this->log?->ownerOf($at),
            'fields' => array_map(static fn (array $sides): array => ['old' => $sides[0], 'new' => $sides[1]], $fields),
            'context' => $context,
        ];
    }

    /**
     * One fact of the rows by its place among them -- the key eachRowFact() gives it -- as
     * eachRowFact() gives it.
     *
     * @return array{statement: string, class: class-string, id: string, key: array<string, mixed>, at: int, flush: int|null, fields: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>}
     */
    public function rowFactAt(int $index): array
    {
        $kept = $this->rowFacts[$index] ?? throw new \OutOfBoundsException(sprintf('No fact of the rows at %d.', $index));
        $fact = $this->asRead($kept);

        // Read after it was let go: whatever asks has come after the history was written from
        // it, and would make something of a fact that is no longer there.
        if (!isset($fact['key'], $fact['fields'])) {
            throw new \LogicException(sprintf('The fact of the rows at %d was let go once its execution was written; it cannot be read again.', $index));
        }

        return $fact;
    }

    /**
     * Lets go of what the facts at these places say beyond what every reader from the first
     * needs: once the records of an execution are made, its key and fields are nobody's. What
     * stays is what it was, of which row, where, and the row once it ran -- the links read which
     * rows went and came back from the first fact on, and a link's context is the row as it
     * stood at a position, however long ago.
     *
     * The listener lets go of the executions of the reading that writes them, one at a time as
     * each is written. A replay read to count, or one a flush reads again later, lets go of
     * nothing; a replay rebuilt from the log because a rollback reached back has every fact again.
     *
     * @param list<int> $places
     */
    public function letGoOf(array $places): void
    {
        foreach ($places as $place) {
            $fact = $this->rowFacts[$place] ?? null;

            if ($fact === null || $fact[6] === null) {
                continue;
            }

            $this->rowFacts[$place] = [$fact[0], $fact[1], $fact[2], null, null, $fact[5], null, $fact[7]];
        }
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
        $this->facts[] = ['type' => $type, 'id' => $id, 'field' => $field, 'old' => $old, 'new' => $new, 'flush' => $this->log?->ownerOf($this->at), 'at' => $this->at, 'element' => $element, 'failed' => $failed, 'ownerContext' => $element === null ? [] : $this->contextOfTheOwner($element['owner'], $id)];
    }

    /**
     * What a row's always-recorded fields hold where the replay has read to -- which is where
     * the log stands when it is asked during a flush: the context of something that happened
     * there and that is no fact of the row's (what a collection's snapshot says, until its
     * join rows are facts too). Nothing for a row it does not know.
     *
     * @param class-string         $class a class of the row's hierarchy
     * @param array<string, mixed> $key   its identifier columns and their database values
     *
     * @return array<string, mixed>
     */
    public function contextOfTheRow(string $class, array $key): array
    {
        $root = $this->em()->getClassMetadata($this->em()->getClassMetadata($class)->rootEntityName);
        $row = $this->rows[$root->name][self::keyOf($root, $key)] ?? null;

        return $row === null ? [] : $this->contextOf($this->classOfRow($root, $row), $row);
    }

    /**
     * What an owner's always-recorded fields hold in its row at this point of the log: the
     * context of what an element did, which is the owner's row beside the change when the
     * change is in the database -- not as the flush began, and not as the rows stand once the
     * history is written. The owner's own UPDATE need not be anywhere near: the application's
     * SQL may have moved the row between two statements of its elements.
     *
     * @param class-string $owner
     *
     * @return array<string, mixed>
     */
    private function contextOfTheOwner(string $owner, string $id): array
    {
        $root = $this->em()->getClassMetadata($this->em()->getClassMetadata($owner)->rootEntityName);
        $row = $this->rows[$root->name][$id] ?? null;

        return $row === null ? [] : $this->contextOf($this->classOfRow($root, $row), $row);
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

    /**
     * A column written as a stream: the driver read the stream to its end, so what it held is
     * nothing this can read - and a column whose last value was one is not known either, so
     * the next change of it has no old side to give.
     *
     * A failure as well as a doubt, reported through the policy as a representer's is: unlike
     * a statement the log could not follow, this is a declaration the application can change,
     * and inside an AuditTransaction a history short of a field is no reason to commit.
     */
    private function doubtOfAStream(string $class, string $field, bool $written): void
    {
        $this->doubt(sprintf('not read: %s::$%s was written as a stream, which the driver read to its end - record its size, a checksum or a file\'s identifier instead', $class, $field), $class);

        $failure = new DeclarationMistake(sprintf(
            $written
                ? '%s::$%s was written as a stream, which the driver read to its end, so the history cannot say what it holds: this change of it is not in the history, the rest of the record is. Audit what describes it instead - its size, a checksum or the identifier of the file.'
                : '%s::$%s changed from a value written as a stream, which the history never read, so this change of it has no old side and is not in the history; the rest of the record is. Audit what describes it instead - its size, a checksum or the identifier of the file.',
            $class,
            $field,
        ));
        $this->failures[] = $failure;
        $this->failuresAt[] = ['at' => $this->at, 'failure' => $failure];
    }

    private function doubt(string $text, ?string $class): void
    {
        $this->doubtOf($text, $class === null ? [] : [$class], [], $class === null);
    }

    /**
     * One doubt of a statement: of each watched class whose table it named, of each whose table a
     * name of it may be ({@see TableName::mayBe()}), and of a table it may have written and this
     * cannot name.
     *
     * @param list<string> $classes
     * @param list<string> $like
     */
    private function doubtOf(string $text, array $classes, array $like = [], bool $unnamed = false): void
    {
        $this->doubts[] = $text;
        $this->doubtsAt[] = ['at' => $this->at, 'classes' => $classes, 'like' => $like, 'unnamed' => $unnamed];
    }

    /**
     * The watched classes whose table -- or join table -- a name not the mapping's may be: grounds
     * for a doubt only, never for binding the statement to them.
     *
     * @return list<string>
     */
    private function watchedClassesItMayBe(TableName $name): array
    {
        $classes = [];

        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $candidate) {
            if (!$candidate instanceof ClassMetadata || !$this->watched->areWatched($this->em(), $candidate)) {
                continue;
            }

            $tables = [$candidate->getTableName()];

            foreach ($candidate->getAssociationNames() as $association) {
                $joinTable = CollectionRowsQuery::entry($candidate->getAssociationMapping($association), 'joinTable');
                $tables[] = $joinTable === null ? null : CollectionRowsQuery::entry($joinTable, 'name');
            }

            foreach ($tables as $table) {
                if (\is_string($table) && $name->mayBe($table)) {
                    $classes[] = $candidate->rootEntityName;
                }
            }
        }

        return $classes;
    }

    /**
     * The doubts of the statements after a position: where, and about which classes, and nothing a
     * statement carried -- for a writer to say what the history may be missing.
     *
     * @return list<array{at: int, classes: list<string>, like: list<string>, unnamed: bool}>
     */
    public function doubtsAfter(int $at): array
    {
        return array_values(array_filter($this->doubtsAt, static fn (array $doubt): bool => $doubt['at'] > $at));
    }

    /**
     * A statement of a join table, kept if the association's links are watched.
     */
    private function linkStatement(StatementShape $shape, RowBinding $binding, ?int $affected): void
    {
        if ($binding->class === null || $binding->association === null) {
            return;
        }

        $owner = $this->em()->getClassMetadata($binding->class);

        if (!$this->watched->areLinksWatched($owner, $binding->association)) {
            return;
        }

        $target = $this->em()->getClassMetadata($this->em()->getClassMetadata($owner->getAssociationTargetClass($binding->association))->rootEntityName);
        $of = JoinRowMemory::associationOf($owner->rootEntityName, $binding->association);

        if ($binding->kind === RowBinding::JOIN_ROWS_OF_TARGET) {
            $key = $binding->element ?? [];
            $this->links[$of]['targets'][] = ['at' => $this->at, 'target' => self::keyOf($target, $key), 'key' => $key, 'affected' => $affected, 'by' => 'statement'];

            return;
        }

        $ownerKey = $binding->key ?? [];
        $this->links[$of]['owners'][self::keyOf($this->em()->getClassMetadata($owner->rootEntityName), $ownerKey)][] = [
            'at' => $this->at,
            'kind' => $binding->kind === RowBinding::JOIN_ROWS_OF_OWNER ? 'all' : ($shape->kind === StatementShape::INSERT ? 'add' : 'remove'),
            'owner' => $ownerKey,
            'target' => $binding->element === null ? null : self::keyOf($target, $binding->element),
            'key' => $binding->element,
            'affected' => $affected,
        ];
    }

    /**
     * A row that went, taken out of every watched collection its class is a target of.
     *
     * @param class-string         $root
     * @param array<string, mixed> $key
     */
    private function aTargetWent(string $root, array $key): void
    {
        $metadata = $this->em()->getClassMetadata($root);

        foreach ($this->watched->linksTo($this->em(), $metadata) as [$owner, $association]) {
            $of = JoinRowMemory::associationOf($this->em()->getClassMetadata($owner)->rootEntityName, $association);
            $this->links[$of]['targets'][] = ['at' => $this->at, 'target' => self::keyOf($metadata, $key), 'key' => $key, 'affected' => 1, 'by' => 'row'];
        }
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
     * @param int|string|null           $handedOut the key the database handed out for this INSERT's row, as the connection
     *                                              answered right after it ({@see StatementLog::keyHandedOut()})
     */
    private function inserted(ClassMetadata $metadata, StatementShape $shape, array $params, ?array $key, int|string|null $handedOut): void
    {
        $row = [];

        foreach ($shape->assigned as $column => $parameter) {
            $row[$column] = $parameter === null ? null : $params[$parameter] ?? null;
        }

        if ($key === null) {
            // The row's key from its own INSERT, as the connection gave it out; never from another
            // execution's. A row whose key is more than one column is never one the database hands
            // out, and one whose key nobody asked the connection for is doubt.
            $columns = $metadata->getIdentifierColumnNames();

            if ($handedOut === null || \count($columns) !== 1) {
                $this->doubt('an INSERT into '.$shape->table.' whose key the database handed out and nothing asked the connection for', $metadata->rootEntityName);

                return;
            }

            // As the database gave it, like every key read off a statement's parameters: it is
            // made the field's value where an id is built from it (RowIdentity::identifierValues()).
            $row[$columns[0]] = $handedOut;

            $this->forgetWhatWasTakenAfter($metadata->rootEntityName, self::keyOf($metadata, $row), $this->at);
        }

        $root = $metadata->rootEntityName;
        $id = self::keyOf($metadata, $row);

        // A row deleted and inserted again under its key starts over; a row already there --
        // the root table's, when a JOINED child writes its own -- is added to, the new columns
        // winning, and it arrived once.
        $before = isset($this->gone[$root][$id]) ? null : ($this->rows[$root][$id] ?? null);
        $there = $before ?? [];
        $this->rows[$root][$id] = $row + $there;
        unset($this->gone[$root][$id]);
        $this->moved($root, $id, $before);

        foreach ($there === [] ? $this->ownersOf($metadata, $this->rows[$root][$id]) : [] as $owner) {
            $this->member($metadata, $owner, $id, self::keyColumnsOf($metadata, $this->rows[$root][$id]), $this->rows[$root][$id], arrived: true);
        }

        // The creation, from what this table's INSERT wrote: every audited column of it, from
        // nothing.
        $this->rowFact(StatementShape::INSERT, $metadata, $this->rows[$root][$id], array_map(
            static fn (mixed $value): array => ['old' => null, 'new' => $value],
            $this->auditedColumns($metadata, $this->rows[$root][$id], array_keys($row)),
        ));
    }

    /**
     * Whether the connection counts the rows an UPDATE changed rather than those it reached:
     * MySQL's, and MariaDB's, unless told otherwise -- which the replay does not assume.
     */
    private function countsTheRowsItChanged(): bool
    {
        return $this->countsChanged ??= $this->em()->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
    }

    private ?bool $countsChanged = null;

    /**
     * @param ClassMetadata<object>   $metadata
     * @param array<array-key, mixed> $params
     * @param array<string, mixed>    $key
     */
    private function updated(ClassMetadata $metadata, StatementShape $shape, array $params, array $key, ?int $affected = null): void
    {
        $root = $metadata->rootEntityName;
        $id = self::keyOf($metadata, $key);

        if (isset($this->gone[$root][$id])) {
            return; // an UPDATE of a row a DELETE that stayed done had taken reached nothing
        }

        // An UPDATE the database says reached no row: none was there, whatever is remembered of
        // it -- ORM 2.19 writes the changes of an entity it has just removed, and the row it is
        // remembered by at the next preFlush is Doctrine's, not the database's. MySQL counts the
        // rows it changed instead, and there none is no row that took a new value: below. A count
        // the driver did not give says nothing.
        $reachedNothing = $affected === 0;

        if ($reachedNothing && !$this->countsTheRowsItChanged()) {
            return;
        }

        if (!isset($this->rows[$root][$id])) {
            $this->doubt('an UPDATE of '.$root.' '.$id.', a row nothing said was there', $root);

            return;
        }

        $before = $this->rows[$root][$id];

        // On MySQL: the database says no row took a new value -- the row was not there, or held
        // the values already. Either way the row is as it was, whatever is remembered of it, and
        // the database is believed: the statement ran and changed nothing. For a line of a
        // collection too, which is no audited row of its own -- a line another process deleted
        // was read as changed, in its owner's history, when only an audited row's own fields were
        // asked.
        if ($reachedNothing) {
            $this->rowFact(StatementShape::UPDATE, $metadata, $before, []);

            return;
        }

        $after = $before;

        foreach ($shape->assigned as $column => $parameter) {
            // An expression -- a version's -- is not followed: the column is not known after it.
            $after[$column] = $parameter === null ? null : $params[$parameter] ?? null;
        }

        // The change, from what this statement wrote: the audited columns it set, each from the
        // row's value -- a foreign key as the key it names -- and only those that moved.
        $was = $this->auditedColumns($metadata, $before, array_keys($shape->assigned));
        $is = $this->auditedColumns($metadata, $after, array_keys($shape->assigned));
        $changed = [];

        foreach ($is as $field => $value) {
            if (!self::same($was[$field] ?? null, $value)) {
                $changed[$field] = ['old' => $was[$field] ?? null, 'new' => $value];
            }
        }

        $this->rows[$root][$id] = $after;
        $this->moved($root, $id, $before);
        $this->rowFact(StatementShape::UPDATE, $metadata, $after, $changed);

        $ownersBefore = self::byColumn($this->ownersOf($metadata, $before));
        $ownersAfter = self::byColumn($this->ownersOf($metadata, $after));
        $audited = $this->audited->forClass($metadata->name, $metadata->newInstance(...));

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
     * A fact about a row of an audited class, or nothing for a class nobody audits.
     *
     * @param ClassMetadata<object>                          $metadata the mapping of the table the statement wrote
     * @param array<string, mixed>                           $row      the row as the replay holds it, every table of it
     * @param array<string, array{old: mixed, new: mixed}>   $fields
     */
    private function rowFact(string $statement, ClassMetadata $metadata, array $row, array $fields): void
    {
        $class = $this->classOfRow($metadata, $row);

        if ($this->audited->forClass($class->name, $class->newInstance(...)) === null) {
            return;
        }

        // A field written as a stream is not a value the history read: doubt, named, and the
        // rest of the row's change is kept - the row is true in every other column. Nor is
        // the next change of it, whose old side is the stream's.
        foreach ($fields as $field => $sides) {
            if (\is_resource($sides['old']) || \is_resource($sides['new'])) {
                $this->doubtOfAStream($class->name, $field, \is_resource($sides['new']));
                unset($fields[$field]);
            }
        }

        $key = self::keyColumnsOf($metadata, $row);
        $this->rowFacts[] = [
            $statement,
            $class->name,
            self::keyOf($metadata, $row),
            $this->keyColumns[$metadata->name] ??= array_keys($key),
            array_values($key),
            $this->at,
            array_map(static fn (array $sides): array => [$sides['old'], $sides['new']], $fields),
            $this->contextOf($class, $row),
        ];
    }

    /**
     * What a class's always-recorded fields hold in a row, as the object holds them: the ones
     * the row has a column for. An association is not context ({@see ChangeSetBuilder::withAlwaysRecorded()}).
     *
     * @param ClassMetadata<object> $class the row's own class
     * @param array<string, mixed>  $row
     *
     * @return array<string, mixed>
     */
    private function contextOf(ClassMetadata $class, array $row): array
    {
        $context = [];

        foreach ($this->audited->forClass($class->name, $class->newInstance(...))->alwaysRecorded ?? [] as $field) {
            if ($class->hasField($field) && \array_key_exists($column = $class->getColumnName($field), $row)) {
                $context[$field] = $this->php($class, $field, $row[$column]);
            }
        }

        return $context;
    }

    /**
     * The class a row is of: the mapping's own, or -- in a hierarchy -- the one its
     * discriminator names, so that a subclass's audited columns are known wherever they are.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     *
     * @return ClassMetadata<object>
     */
    private function classOfRow(ClassMetadata $metadata, array $row): ClassMetadata
    {
        $root = $this->em()->getClassMetadata($metadata->rootEntityName);
        $column = $root->discriminatorColumn['name'] ?? null;
        $value = $column === null ? null : ($row[$column] ?? null);
        $class = $value === null ? null : ($root->discriminatorMap[$value] ?? null);

        return $class === null ? $metadata : $this->em()->getClassMetadata($class);
    }

    /**
     * The audited fields among some columns of a row, with the row's values as the object
     * holds them: a field through its type, a single-valued association as the key it names.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     * @param list<string>          $columns
     *
     * @return array<string, mixed>
     */
    private function auditedColumns(ClassMetadata $metadata, array $row, array $columns): array
    {
        $class = $this->classOfRow($metadata, $row);
        $declaration = $this->audited->forClass($class->name, $class->newInstance(...));

        if ($declaration === null) {
            return [];
        }

        $values = [];

        foreach ($columns as $column) {
            $field = $class->fieldNames[$column] ?? null;

            if ($field !== null) {
                if (\array_key_exists($field, $declaration->fields)) {
                    $values[$field] = $this->php($class, $field, $row[$column] ?? null);
                }

                continue;
            }

            foreach ($class->getAssociationNames() as $association) {
                if (!$class->isSingleValuedAssociation($association) || !\array_key_exists($association, $declaration->fields)) {
                    continue;
                }

                $joinColumns = CollectionRowsQuery::entry($class->getAssociationMapping($association), 'joinColumns');

                if (\is_array($joinColumns) && \count($joinColumns) === 1 && CollectionRowsQuery::entry(reset($joinColumns), 'name') === $column) {
                    $values[$association] = $row[$column] ?? null;
                }
            }
        }

        return $values;
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
    private function deleted(ClassMetadata $metadata, array $key, ?int $affected): void
    {
        // A DELETE counts the rows it took on every engine; none means there was no row. A
        // count not known is not none.
        if ($affected === 0) {
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

        // The removal, with the row as it stood before it went -- every table of it, which the
        // replay holds as one row -- taken now, before the row is let go of.
        $gone = $this->rows[$root][$id];
        $this->rowFact(StatementShape::DELETE, $metadata, $gone, array_map(
            static fn (mixed $value): array => ['old' => $value, 'new' => null],
            $this->auditedColumns($this->classOfRow($metadata, $gone), $gone, array_keys($gone)),
        ));

        $this->gone[$root][$id] = true;
        $this->goneAt[$root][$id] = $this->at;
        $this->moved($root, $id, $gone);
    }

    /**
     * A row as it stood at a position of the log -- before the statement there, or once it ran
     * -- as a copy of its class: what a link's target is shown as at the moment of the link,
     * where the replay knows the row. Null where it does not: a row it holds no account of from
     * that point, or one that was not there.
     *
     * @param class-string         $class a class of the row's hierarchy
     * @param array<string, mixed> $key   its identifier columns and their database values
     */
    public function copyAt(string $class, array $key, int $position, bool $once): ?object
    {
        $em = $this->em();
        $root = $em->getClassMetadata($em->getClassMetadata($class)->rootEntityName);

        // A row of a class no audited association points at keeps no versions, and is not shown
        // at a position: where it stood is not known.
        if (!$this->shownAsItStood($root->name)) {
            return null;
        }
        $row = $this->rowAt($root->name, self::keyOf($root, $key), $position, $once);

        if ($row === null) {
            return null;
        }

        $metadata = $this->classOfRow($root, $row);
        $copy = $this->copyOf($metadata, $row);

        // And what it points at, by the key its row held there -- so that a representer reading
        // the row's own association reads the one the row pointed at. What that entity holds is
        // the manager's: the row is shown as it stood, not everything it leads to.
        foreach ($metadata->getAssociationNames() as $association) {
            if (!$metadata->isSingleValuedAssociation($association) || $metadata->isAssociationInverseSide($association)) {
                continue;
            }

            $columns = CollectionRowsQuery::entry($metadata->getAssociationMapping($association), 'joinColumns');
            $column = \is_array($columns) && \count($columns) === 1 ? CollectionRowsQuery::entry(reset($columns), 'name') : null;

            if (!\is_string($column) || !\array_key_exists($column, $row)) {
                continue;
            }

            $metadata->setFieldValue($copy, $association, $row[$column] === null ? null : $this->referenceTo($metadata->getAssociationTargetClass($association), $row[$column]));
        }

        return $copy;
    }

    /**
     * The entity a foreign key names: the one the manager holds, or a reference to it.
     *
     * @param class-string $class
     */
    private function referenceTo(string $class, mixed $key): ?object
    {
        $metadata = $this->em()->getClassMetadata($class);
        $fields = $metadata->getIdentifierFieldNames();

        if (\count($fields) !== 1) {
            return null;
        }

        $id = [$fields[0] => $metadata->hasAssociation($fields[0]) ? $key : $this->php($metadata, $fields[0], $key)];
        $found = $this->em()->getUnitOfWork()->tryGetById($id, $metadata->rootEntityName);

        return \is_object($found) ? $found : $this->em()->getReference($metadata->name, $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowAt(string $root, string $id, int $position, bool $once): ?array
    {
        $versions = $this->versions[$root][$id] ?? null;

        if ($versions === null) {
            // Unmoved since it was taken: as it is, from where it was taken on.
            $known = !isset($this->takenAt[$root][$id]) || $this->takenAt[$root][$id] <= $position;

            return $known && !isset($this->gone[$root][$id]) ? ($this->rows[$root][$id] ?? null) : null;
        }

        $row = null;
        $found = false;

        foreach ($versions as [$at, $held]) {
            if ($at > $position || (!$once && $at === $position && $found)) {
                break;
            }

            $row = $held;
            $found = true;
        }

        return $found ? $row : null;
    }

    /**
     * A row moved by the statement being read: what it holds now, and -- the first time -- what
     * it held before, where it was taken. Only for a row that may be shown as it stood
     * ({@see WatchedRows::areShownAsTheyStood()}): nothing asks after the versions of any other.
     *
     * @param array<string, mixed>|null $before
     */
    private function moved(string $root, string $id, ?array $before): void
    {
        if (!$this->shownAsItStood($root)) {
            return;
        }

        if (!isset($this->versions[$root][$id])) {
            $this->versions[$root][$id][] = [$this->takenAt[$root][$id] ?? 0, $before];
        }

        $this->versions[$root][$id][] = [$this->at, isset($this->gone[$root][$id]) ? null : ($this->rows[$root][$id] ?? null)];
    }

    /** @var array<string, bool> by root class */
    private array $shown = [];

    private function shownAsItStood(string $root): bool
    {
        return $this->shown[$root] ??= $this->watched->areShownAsTheyStood($this->em(), $this->em()->getClassMetadata($root));
    }

    /**
     * The entity a row a DELETE took was, as the row stood before it went: a copy of the row's
     * class with every column through its type -- what a representer is shown of a removed
     * entity, the same as of a removed element. Its associations are not there: nothing of
     * another row is read to fill them in.
     *
     * Null when no DELETE this replay read took it: the row is there, or nothing watched it.
     *
     * @param class-string         $class a class of the row's hierarchy
     * @param array<string, mixed> $key   its identifier columns and their database values
     */
    public function asItStoodBeforeItWent(string $class, array $key): ?object
    {
        $root = $this->em()->getClassMetadata($this->em()->getClassMetadata($class)->rootEntityName);
        $id = self::keyOf($root, $key);

        if (!isset($this->gone[$root->name][$id], $this->rows[$root->name][$id])) {
            return null;
        }

        $row = $this->rows[$root->name][$id];

        return $this->copyOf($this->classOfRow($root, $row), $row);
    }

    /**
     * A new object of a class holding a row's columns, each through its type.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     */
    private function copyOf(ClassMetadata $metadata, array $row): object
    {
        $copy = $metadata->newInstance();

        foreach ($metadata->getFieldNames() as $field) {
            $metadata->setFieldValue($copy, $field, $this->php($metadata, $field, $row[$metadata->getColumnName($field)] ?? null));
        }

        return $copy;
    }

    /**
     * Whether a DELETE of this flush took the row: what an owner's collection went through in
     * the flush that removed the owner is its remove, not a second event.
     */
    public function removedIn(string $root, string $id, int $flush): bool
    {
        return isset($this->gone[$root][$id], $this->goneAt[$root][$id]) && $this->log?->ownerOf($this->goneAt[$root][$id]) === $flush;
    }

    private function emptied(StatementShape $shape, RowBinding $binding, ?int $affected): void
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
        if ($affected !== null && \count($held) !== $affected) {
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
            $this->moved($root, (string) $id, $row);
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
        $audited = $this->audited->forClass($owner->name, $owner->newInstance(...));

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
        $copy = $this->copyOf($metadata, $row);
        $represent = $this->audited->forClass($owner->name, $owner->newInstance(...))?->fields[$collection] ?? null;

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
     * The watched class a table holds the rows of, if any: its own table, or the join table of
     * one of its collections. Any other table -- an unwatched class's, or one no mapping names
     * -- is the application's own.
     */
    private function watchedClassOf(string $table): ?string
    {
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $candidate) {
            if (!$candidate instanceof ClassMetadata || !$this->watched->areWatched($this->em(), $candidate)) {
                continue;
            }

            if ($candidate->getTableName() === $table) {
                return $candidate->rootEntityName;
            }

            foreach ($candidate->getAssociationNames() as $association) {
                $joinTable = CollectionRowsQuery::entry($candidate->getAssociationMapping($association), 'joinTable');

                if ($joinTable !== null && CollectionRowsQuery::entry($joinTable, 'name') === $table) {
                    return $candidate->rootEntityName;
                }
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

        // A stream bound as a parameter has been read by the driver already, to its end:
        // converting it now - BinaryType reads what is left of it - made the history say the
        // column became '' while the row holds every byte. Left as it is, it is refused where
        // every stream is, by its value, with what to record instead.
        if (\is_resource($value)) {
            return $value;
        }

        $type = $metadata->getTypeOfField($field);
        $value = \is_string($type) ? Type::getType($type)->convertToPHPValue($value, $this->em()->getConnection()->getDatabasePlatform()) : $value;

        // An enum is the case the object holds, not the string its column does: hydration makes
        // it one, and the type alone does not.
        $enum = CollectionRowsQuery::entry($metadata->getFieldMapping($field), 'enumType');

        if (\is_string($enum) && is_subclass_of($enum, \BackedEnum::class) && (\is_int($value) || \is_string($value))) {
            return $enum::from($value);
        }

        return $value;
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
