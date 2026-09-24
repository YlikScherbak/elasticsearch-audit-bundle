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

    /** Where the log stood when these rows were last settled; what came after is replayed over them. */
    private int $settledAt = 0;

    private readonly WatchedRows $watched;

    /**
     * The replay of what came after the rows were settled, kept and carried on: reading what
     * the rows hold now reads only what is new. Made again only when a rollback has reached
     * back into what it had read, or a different manager asks.
     */
    private ?HistoryReplay $current = null;

    private int $currentVoided = 0;

    /** Statements read by replays made and let go before the current one, for the tests of what reading costs. */
    private int $readBefore = 0;

    private ?EntityManagerInterface $currentManager = null;

    /** @var array<string, list<object>> every entity postPersist announced since the rows were settled, by root class */
    private array $persisted = [];

    public function __construct(private readonly StatementLog $log, private readonly AuditMetadataFactory $audited = new AuditMetadataFactory(), ?WatchedRows $watched = null)
    {
        $this->watched = $watched ?? new WatchedRows($audited);
    }

    /**
     * At preFlush: every watched row Doctrine manages that is not remembered yet.
     */
    public function rememberWhatIsManaged(EntityManagerInterface $em): void
    {
        foreach ($em->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (\is_object($entity)) {
                    $this->remember($em, $entity);
                }
            }
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
            || $this->currentManager !== $em
            || $this->currentVoided !== $this->log->voided()
            || $this->current->readTo() > $upTo
        ) {
            $this->readBefore += $this->current?->read() ?? 0;
            $this->current = new HistoryReplay($em, $this->rows, $this->takenAt, $this->audited, $this->watched);
            $this->currentVoided = $this->log->voided();
            $this->currentManager = $em;
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
        $this->persisted[$em->getClassMetadata($entity::class)->rootEntityName][] = $entity;
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
        $this->rows = $this->replayed($em, $upTo)->rows();
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

        $key = [];

        foreach ($metadata->getIdentifierValues($entity) as $field => $value) {
            if ($value === null) {
                return; // not inserted yet: there is no row, and its INSERT will say what it holds
            }

            $key[$metadata->getColumnName($field)] = \is_object($value) ? $this->keyOfTarget($em, $value) : $value;
        }

        $id = HistoryReplay::keyOf($metadata, $key);

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
                $row[$name] = $targetMetadata->getFieldValue($target, $targetMetadata->getFieldForColumn($referenced));
            }
        }

        return $row;
    }

    private function keyOfTarget(EntityManagerInterface $em, object $target): mixed
    {
        $values = $em->getClassMetadata($target::class)->getIdentifierValues($target);

        return \count($values) === 1 ? reset($values) : null;
    }
}
