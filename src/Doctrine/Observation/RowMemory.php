<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * What each watched row held, moving with the database rather than taken once.
 *
 * **Where a row's first account comes from.** Doctrine's memory of it at preFlush, before
 * computeChangeSets() writes the plan over that memory, or at postLoad for a row first loaded
 * while a flush is running. The first account of a row is kept: a flush refused in onFlush
 * leaves the memory holding values the row never took, and the next preFlush remembers those.
 * The key is the entity's own, since Doctrine's memory is rewritten without a generated
 * identifier. Only the rows history is kept for -- audited entities and the elements of their
 * audited collections -- are remembered at all.
 *
 * **How it moves.** This holds the rows as they were when the log was last settled. What they
 * hold now is that plus every statement since that the log has not voided -- which is what
 * {@see HistoryReplay} computes, rollbacks and all, so there is no second account of savepoints
 * here. Once the log has no transaction open, {@see settle()} folds what stayed done into the
 * rows and the log may let go of it: a row written 1 -> 2 is remembered as 2, and Doctrine's
 * memory of it, taken again later, is not believed over it.
 *
 * **When a row is forgotten.** When nothing holds its entity any more and nothing unsettled
 * needs it: each row keeps a weak reference to the object it was taken from, and settling drops
 * the ones whose object is gone. Doctrine raises no event for detach(), and none is needed --
 * what the application let go of, the collector takes, and the row is remembered afresh from
 * the next object loaded for it. A row an unsettled statement still needs survives a clear():
 * it is keyed by the row, not by the object, and settling comes after publishing. So what is
 * held is the audited entities Doctrine manages, and the work not yet settled.
 */
final class RowMemory
{
    /** @var array<string, array<string, array<string, mixed>>> root class => key => column => database value */
    private array $rows = [];

    /** @var array<string, array<string, int>> where the log stood when each row was first taken */
    private array $takenAt = [];

    /** @var array<string, array<string, \WeakReference<object>>> the object each row was taken from */
    private array $objects = [];

    /**
     * Rows a DELETE took, held by the object that was left behind: Doctrine keeps an entity
     * whose row an emptying took -- replacing a collection with orphanRemoval deletes the
     * rows with one statement and leaves the objects managed -- and its memory of that row,
     * taken again at the next preFlush, would be a row the table does not have. Kept while
     * that object is; a row loaded again, or inserted again under its key, is another matter.
     *
     * @var array<string, array<string, \WeakReference<object>>>
     */
    private array $departed = [];

    /** Where the log stood when these rows were last settled; what came after is replayed over them. */
    private int $settledAt = 0;

    private readonly WatchedRows $watched;

    private readonly JoinRowMemory $links;

    /**
     * The replay of what came after the rows were settled, kept and carried on: reading what
     * the rows hold now reads only what is new. Made again only when a rollback has reached
     * back into what it had read, or a different manager asks.
     */
    private ?HistoryReplay $current = null;

    private int $currentVoided = 0;

    /** Statements read by replays made and let go before the current one, for the tests of what reading costs. */
    private int $readBefore = 0;

    /**
     * Weakly, like the replay's own: a manager the application replaced must be free to go.
     *
     * @var \WeakReference<EntityManagerInterface>|null
     */
    private ?\WeakReference $currentManager = null;

    /**
     * The key of every row postPersist announced since the rows were settled, by root class and
     * in order. The key and not the entity: an entity's collections hold its manager, and a
     * flush whose postFlush never reached this listener leaves the list unsettled -- holding a
     * manager the application has replaced, and everything it managed.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $persisted = [];

    public function __construct(private readonly StatementLog $log, private readonly AuditMetadataFactory $audited = new AuditMetadataFactory(), ?WatchedRows $watched = null)
    {
        $this->watched = $watched ?? new WatchedRows($audited);
        $this->links = new JoinRowMemory($log);

        // From where the log stands: the rows before it are nothing this remembered.
        $this->settledAt = $log->position();
    }

    /**
     * At preFlush: every watched row Doctrine manages that is not remembered yet.
     */
    public function rememberWhatIsManaged(EntityManagerInterface $em): void
    {
        $uow = $em->getUnitOfWork();

        foreach ($uow->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (\is_object($entity)) {
                    $this->remember($em, $entity);
                }
            }
        }

        // And what is about to be removed, which ORM 2.19 has already taken out of the identity
        // map at remove() -- 2.20 and 3 keep it there until the commit. A row loaded and removed
        // before any flush saw it was otherwise a DELETE of a row nothing said was there.
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->remember($em, $entity);
        }
    }

    /**
     * At postLoad: only while a flush is running. A row loaded outside one is remembered at the
     * next preFlush, before anything can have changed it -- and every find() the application
     * makes does not pay for the audit.
     */
    public function rememberLoaded(EntityManagerInterface $em, object $entity, bool $whileAFlushRuns): void
    {
        if ($whileAFlushRuns) {
            $this->remember($em, $entity);
        }
    }

    /**
     * The rows of an inverse collection that is about to be emptied and was never loaded:
     * read as rows, not as entities.
     *
     * The emptying is one DELETE of every row the owner holds, and what it took is only as
     * known as the rows are. Elements the application never loaded were remembered nowhere,
     * so the rows are read once, before the DELETE -- through the connection, by the mapping's
     * columns, and not through the collection: loading it would put entities the application
     * never touched into its identity map and run their postLoad callbacks and listeners,
     * which is the application's code on the bundle's account. A row already remembered is
     * kept as it is, and a row read here is an account of the statements after this point
     * only: the rows have moved since the log began, and this is what they hold now.
     */
    public function rememberTheRowsOf(EntityManagerInterface $em, object $owner, string $association): void
    {
        $ownerMetadata = $em->getClassMetadata($owner::class);

        if (!$ownerMetadata->isAssociationInverseSide($association)) {
            return;
        }

        $elements = $em->getClassMetadata($ownerMetadata->getAssociationTargetClass($association));
        $root = $elements->rootEntityName;
        $mappedBy = $ownerMetadata->getAssociationMappedByTargetField($association);

        if (!$this->watched->areWatched($em, $elements) || !$elements->hasAssociation($mappedBy)) {
            return;
        }

        $joinColumns = CollectionRowsQuery::entry($elements->getAssociationMapping($mappedBy), 'joinColumns');
        $join = \is_array($joinColumns) && \count($joinColumns) === 1 ? reset($joinColumns) : null;
        $column = CollectionRowsQuery::entry($join, 'name');
        $referenced = CollectionRowsQuery::entry($join, 'referencedColumnName');

        if (!\is_string($column) || !\is_string($referenced)) {
            return;
        }

        $ownerField = $ownerMetadata->getFieldForColumn($referenced);
        $ownerKey = $ownerMetadata->hasAssociation($ownerField)
            ? self::keyOfTarget($em, $ownerMetadata->getFieldValue($owner, $ownerField))
            : self::databaseValue($em, $ownerMetadata, $ownerField, $ownerMetadata->getFieldValue($owner, $ownerField));

        $platform = $em->getConnection()->getDatabasePlatform();
        $quotes = $em->getConfiguration()->getQuoteStrategy();
        $names = [];
        $selected = [];

        foreach ($elements->getFieldNames() as $field) {
            $names[] = $elements->getColumnName($field);
            $selected[] = $quotes->getColumnName($field, $elements, $platform);
        }

        foreach ($elements->getAssociationNames() as $other) {
            if (!$elements->isSingleValuedAssociation($other) || $elements->isAssociationInverseSide($other)) {
                continue;
            }

            foreach ((array) CollectionRowsQuery::entry($elements->getAssociationMapping($other), 'joinColumns') as $joinColumn) {
                $name = CollectionRowsQuery::entry($joinColumn, 'name');

                if (\is_string($name)) {
                    $names[] = $name;
                    $selected[] = CollectionRowsQuery::entry($joinColumn, 'quoted') !== null ? $platform->quoteIdentifier($name) : $name;
                }
            }
        }

        // Read by position: a database that folds unquoted names would hand the keys back in
        // a case of its own.
        $rows = $em->getConnection()->fetchAllNumeric(
            sprintf('SELECT %s FROM %s WHERE %s = ?', implode(', ', $selected), $quotes->getTableName($elements, $platform), CollectionRowsQuery::entry($join, 'quoted') !== null ? $platform->quoteIdentifier($column) : $column),
            [$ownerKey],
        );

        foreach ($rows as $values) {
            $row = array_combine($names, $values);
            $id = HistoryReplay::keyOf($elements, $row);

            if (isset($this->rows[$root][$id])) {
                continue; // known: the account it has is the earlier one
            }

            $this->rows[$root][$id] = $row;
            $this->takenAt[$root][$id] = $this->log->position();
            $this->current?->learn($root, $id, $row, $this->takenAt[$root][$id]);
        }
    }

    /**
     * At onFlush, every flush's: what the join rows this flush is about to change hold, before
     * it changes them -- the links of every watched collection it plans to write, the holders of
     * every target it plans to remove, and the links of an owner it plans to remove where its
     * join columns do not cascade and Doctrine takes them by a statement of its own. Read where
     * the log stands, which in a nested flush is after what the outer one has written.
     *
     * What failed is handed back for the listener's policy: a question that fails must not take
     * the flush with it, and the account it would have given is then not known.
     *
     * @return list<\Throwable>
     */
    public function rememberTheLinksAboutToChange(EntityManagerInterface $em): array
    {
        $uow = $em->getUnitOfWork();
        $includes = fn (string $of, string $id): array => $this->replayed($em)->linkPositionsOf($of, $id);
        $failures = [];

        foreach ([...array_values($uow->getScheduledCollectionUpdates()), ...array_values($uow->getScheduledCollectionDeletions())] as $collection) {
            try {
                $owner = $collection->getOwner();
                $field = CollectionRowsQuery::entry($collection->getMapping(), 'fieldName');

                if ($owner !== null && \is_string($field) && $this->watched->areLinksWatched($em->getClassMetadata($owner::class), $field)) {
                    $this->links->rememberTheLinksOf($em, $owner, $field, $includes);
                }
            } catch (\Throwable $e) {
                $failures[] = $e;
            }
        }

        // The targets going, gathered by the collection they may be in: one question for all of
        // a collection's, however many a flush removes.
        $going = [];

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            try {
                $metadata = $em->getClassMetadata($entity::class);

                foreach ($this->watched->linksTo($em, $metadata) as [$owner, $association]) {
                    $going[$owner][$association][] = $entity;
                }

                foreach ($metadata->getAssociationNames() as $association) {
                    if ($this->watched->areLinksWatched($metadata, $association) && !self::cascades($metadata->getAssociationMapping($association))) {
                        $this->links->rememberTheLinksOf($em, $entity, $association, $includes);
                    }
                }
            } catch (\Throwable $e) {
                $failures[] = $e;
            }
        }

        foreach ($going as $owner => $associations) {
            foreach ($associations as $association => $targets) {
                try {
                    $this->links->rememberTheHoldersOf($em, $targets, $owner, $association, $includes);
                } catch (\Throwable $e) {
                    $failures[] = $e;
                }
            }
        }

        return $failures;
    }

    /** What the join rows held, as last settled and as read since. */
    public function links(): JoinRowMemory
    {
        return $this->links;
    }

    /**
     * Whether the database takes an owner's join rows with its row: every join column of the
     * owner's side cascading on delete, which is Doctrine's default -- and then Doctrine writes
     * no statement of the join table for it.
     */
    private static function cascades(mixed $mapping): bool
    {
        $columns = CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'joinColumns');

        if (!\is_array($columns) || $columns === []) {
            return false;
        }

        foreach ($columns as $column) {
            $onDelete = CollectionRowsQuery::entry($column, 'onDelete');

            if (!\is_string($onDelete) || strtoupper($onDelete) !== 'CASCADE') {
                return false;
            }
        }

        return true;
    }

    /**
     * The rows as last settled, by root class and key.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Where the log stood when each was taken: a row taken after a statement ran is no account
     * of the row before it.
     *
     * @return array<string, array<string, int>>
     */
    public function takenAt(): array
    {
        return $this->takenAt;
    }

    public function settledAt(): int
    {
        return $this->settledAt;
    }

    /**
     * What the rows hold now: as last settled, with everything since that the log has not
     * voided replayed over them.
     */
    public function replayed(EntityManagerInterface $em, ?int $upTo = null): HistoryReplay
    {
        $upTo ??= $this->log->position();

        if ($this->current === null
            || $this->currentManager?->get() !== $em
            || $this->currentVoided !== $this->log->voided()
            || $this->current->readTo() > $upTo
        ) {
            $this->readBefore += $this->current?->read() ?? 0;
            $this->current = new HistoryReplay($em, $this->rows, $this->takenAt, $this->audited, $this->watched);
            $this->currentVoided = $this->log->voided();
            $this->currentManager = \WeakReference::create($em);
        }

        $this->current->replay($this->log, $this->settledAt, $upTo, $this->persisted);

        return $this->current;
    }

    /**
     * At postPersist: a row whose key the database handed out is bound to its INSERT by the
     * order these were announced in.
     */
    public function rememberPersisted(EntityManagerInterface $em, object $entity): void
    {
        // Every one, whatever its key: the order has to line up with every INSERT without one.
        $metadata = $em->getClassMetadata($entity::class);
        $key = self::keyColumns($em, $entity);
        $this->persisted[$metadata->rootEntityName][] = $key ?? [];

        // And the row its INSERT makes is held by this object from now on, as a remembered row
        // is by the one it was taken from. Without that, settling let go of every row a flush
        // had just inserted, and the next preFlush took it again from Doctrine's memory -- after
        // whatever the application had written to it meanwhile, which Doctrine does not know.
        if ($key !== null && $key !== [] && $this->watched->areWatched($em, $metadata)) {
            $this->objects[$metadata->rootEntityName][HistoryReplay::keyOf($metadata, $key)] = \WeakReference::create($entity);
        }
    }

    /**
     * A row's key as the statements carry it -- column => database value -- or null for an
     * entity not inserted yet.
     *
     * @return array<string, mixed>|null
     */
    public static function keyColumns(EntityManagerInterface $em, object $entity): ?array
    {
        $metadata = $em->getClassMetadata($entity::class);
        $key = [];

        foreach ($metadata->getIdentifierValues($entity) as $field => $value) {
            if ($value === null) {
                return null;
            }

            $key[$metadata->getColumnName($field)] = $metadata->hasAssociation($field)
                ? self::keyOfTarget($em, $value)
                : self::databaseValue($em, $metadata, $field, $value);
        }

        return $key;
    }

    /**
     * Folds what stayed done into the rows, once nothing in the log can still be rolled back,
     * and forgets the rows nothing holds.
     *
     * Returns whether it did: inside a transaction that is still open -- an application's own,
     * around the flush -- nothing is final, and the rows wait.
     */
    public function settle(EntityManagerInterface $em): bool
    {
        if ($this->log->inTransaction()) {
            return false;
        }

        $upTo = $this->log->position();
        $replay = $this->replayed($em, $upTo);
        $this->rows = $replay->rows();

        foreach ($replay->goneRows() as $root => $ids) {
            foreach ($ids as $id) {
                if (($this->objects[$root][$id] ?? null)?->get() !== null) {
                    $this->departed[$root][$id] = $this->objects[$root][$id];
                }
            }
        }

        foreach ($this->departed as $root => $ids) {
            foreach ($ids as $id => $object) {
                if ($object->get() === null || isset($this->rows[$root][$id])) {
                    unset($this->departed[$root][$id]); // let go, or a row again
                }
            }
        }
        // And the links with them, as the replay tells them. An account it cannot tell is let go of
        // rather than believed: it is read again the next time a flush is about to touch it.
        try {
            $this->links->settle($em, LinkFacts::of($em, $replay, $this->log, $this->links)->states(), $upTo);
        } catch (\Throwable) {
            $this->links->settle($em, [], $upTo);
        }

        $this->settledAt = $upTo;
        $this->takenAt = [];
        $this->current = null;
        $this->persisted = [];

        foreach ($this->rows as $root => $keys) {
            foreach (array_keys($keys) as $id) {
                if (($this->objects[$root][$id] ?? null)?->get() === null) {
                    unset($this->rows[$root][$id], $this->objects[$root][$id]);
                }
            }

            if ($this->rows[$root] === []) {
                unset($this->rows[$root], $this->objects[$root]);
            }
        }

        return true;
    }

    /** How many statements every read of what the rows hold has read, together. */
    public function statementsRead(): int
    {
        return $this->readBefore + ($this->current?->read() ?? 0);
    }

    /** How many rows are held, for the tests that pin what it costs. */
    public function size(): int
    {
        return array_sum(array_map('count', $this->rows));
    }

    private function remember(EntityManagerInterface $em, object $entity): void
    {
        $metadata = $em->getClassMetadata($entity::class);
        $root = $metadata->rootEntityName;

        if (!$this->watched->areWatched($em, $metadata)) {
            return;
        }

        // Not a row yet, whatever it knows about itself. An id the application assigns, or
        // one a sequence hands out at persist() -- PostgreSQL under DBAL 3 -- is there before
        // the INSERT, and a refused flush fills in Doctrine's memory of the row it meant to
        // write: remembered, it was a row the table did not have. Its INSERT says what it holds.
        if ($em->getUnitOfWork()->isScheduledForInsert($entity)) {
            return;
        }

        $key = self::keyColumns($em, $entity);

        if ($key === null) {
            return; // not inserted yet: there is no row, and its INSERT will say what it holds
        }

        $id = HistoryReplay::keyOf($metadata, $key);

        // The object a DELETE left behind: what Doctrine remembers of its row is a row that
        // went.
        if (($this->departed[$root][$id] ?? null)?->get() === $entity) {
            return;
        }

        if (isset($this->rows[$root][$id])) {
            // Known: kept as it is. A new object for it -- the row loaded again after a clear --
            // is what now holds it alive.
            if (($this->objects[$root][$id] ?? null)?->get() !== $entity) {
                $this->objects[$root][$id] = \WeakReference::create($entity);
            }

            return;
        }

        $original = $em->getUnitOfWork()->getOriginalEntityData($entity);

        if ($original === []) {
            return; // Doctrine holds no account of the row yet
        }

        $this->rows[$root][$id] = $key + $this->columnsOf($em, $metadata, $original);
        $this->takenAt[$root][$id] = $this->log->position();
        $this->objects[$root][$id] = \WeakReference::create($entity);
        $this->current?->learn($root, $id, $this->rows[$root][$id], $this->takenAt[$root][$id]);
    }

    /**
     * Doctrine's memory of a row as columns and database values: a foreign key as the key it
     * names, never the object, which may be detached or changed by the time it is read.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $original
     *
     * @return array<string, mixed>
     */
    private function columnsOf(EntityManagerInterface $em, ClassMetadata $metadata, array $original): array
    {
        $platform = $em->getConnection()->getDatabasePlatform();
        $row = [];

        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field)) {
                continue;
            }

            $type = $metadata->getTypeOfField($field);
            $value = $original[$field] ?? null;
            $row[$metadata->getColumnName($field)] = $value === null || !\is_string($type) ? $value : Type::getType($type)->convertToDatabaseValue($value, $platform);
        }

        foreach ($metadata->getAssociationNames() as $association) {
            if (!$metadata->isSingleValuedAssociation($association) || $metadata->isAssociationInverseSide($association)) {
                continue;
            }

            $columns = CollectionRowsQuery::entry($metadata->getAssociationMapping($association), 'joinColumns');
            $target = $original[$association] ?? null;

            foreach (\is_array($columns) ? $columns : [] as $column) {
                $name = CollectionRowsQuery::entry($column, 'name');
                $referenced = CollectionRowsQuery::entry($column, 'referencedColumnName');

                if (!\is_string($name) || !\is_string($referenced)) {
                    continue;
                }

                if (!\is_object($target)) {
                    $row[$name] = null;

                    continue;
                }

                $targetMetadata = $em->getClassMetadata($target::class);
                $targetField = $targetMetadata->getFieldForColumn($referenced);
                $value = $targetMetadata->getFieldValue($target, $targetField);
                $row[$name] = $targetMetadata->hasAssociation($targetField) ? self::keyOfTarget($em, $value) : self::databaseValue($em, $targetMetadata, $targetField, $value);
            }
        }

        return $row;
    }

    /**
     * The key a foreign key holds: the target's identifier as the database has it -- which,
     * for an identifier of a type of its own, is not the object the entity carries.
     */
    private static function keyOfTarget(EntityManagerInterface $em, mixed $target): mixed
    {
        if (!\is_object($target)) {
            return $target;
        }

        $metadata = $em->getClassMetadata($target::class);
        $values = $metadata->getIdentifierValues($target);

        if (\count($values) !== 1) {
            return null;
        }

        $field = (string) array_key_first($values);

        return $metadata->hasAssociation($field) ? self::keyOfTarget($em, reset($values)) : self::databaseValue($em, $metadata, $field, reset($values));
    }

    /**
     * A field's value as the statements carry it: through its type, the way DBAL binds it.
     *
     * @param ClassMetadata<object> $metadata
     */
    private static function databaseValue(EntityManagerInterface $em, ClassMetadata $metadata, string $field, mixed $value): mixed
    {
        $type = $metadata->getTypeOfField($field);

        return $value === null || !\is_string($type) ? $value : Type::getType($type)->convertToDatabaseValue($value, $em->getConnection()->getDatabasePlatform());
    }
}
