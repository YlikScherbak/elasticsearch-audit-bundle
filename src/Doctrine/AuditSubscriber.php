<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadata;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditOrigin;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;

/**
 * Records entity lifecycle events during flush().
 *
 * The records are built while the unit of work still knows the change sets —
 * postPersist, postUpdate, and for removals preRemove (while the entity has its
 * identifier) — but written only in postFlush, once the transaction committed.
 * A flush that fails half-way rolls its INSERTs back and closes the manager;
 * onClear then drops what was collected, so the history never describes a state
 * the database did not reach.
 *
 * A flush inside an outer transaction (wrapInTransaction) is the one case where
 * postFlush still precedes the real commit; the records are sent then anyway,
 * since nothing later would tell the listener the transaction ended.
 *
 * What goes wrong while building a record — a declaration naming an unknown
 * field, an identifier the listener cannot represent — is handed to the writer's
 * failure policy like a failed write, so with the default "log" the flush goes
 * through and the mistake is in the log.
 *
 * @internal register through the bundle configuration (doctrine.enabled)
 */
final class AuditSubscriber
{
    public const EVENTS = [Events::onFlush, Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove, Events::postFlush, Events::onClear];

    /** @var array<class-string, true> classes whose tracked collections were checked against Doctrine's mapping */
    private array $checkedTracking = [];

    /** @var list<AuditRecord> records built during the current flush, written after its commit */
    private array $pending = [];

    /** @var array<int, AuditRecord> records for entities being removed, keyed by object id */
    private array $pendingRemovals = [];

    /** @var array<int, array{0: object, 1: array<string, Change>}> changes inside tracked collection elements, keyed by the owner's object id */
    private array $elementChanges = [];

    /** @var array<int, array{0: object, 1: array<string, array{element: object, added: bool, field: string, value: mixed, id: int|string|null}>}> elements a tracked collection gained or lost, keyed by the owner's object id */
    private array $elementMembership = [];

    /** @var array<int, int> the pending lifecycle record of an entity — create or update — so what its elements did can be folded into it */
    private array $pendingIndexByEntity = [];

    public function __construct(
        private readonly AuditWriter $writer,
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly bool $skipEmptyUpdates = true,
        private readonly ValueComparatorInterface $comparator = new ValueComparator(),
    ) {
    }

    /**
     * The change sets are computed and nothing has been written yet: the only moment
     * where what changed inside the elements of a tracked collection can be seen.
     *
     * The unit of work already knows which entities changed, so no collection is loaded
     * to find out — an element that nobody touched is simply not in the list, and a
     * collection whose elements are all untouched costs nothing at all.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = self::entityManagerOf($args->getObjectManager());

        if ($em === null) {
            return;
        }

        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $element) {
            $this->collectElementChanges($em, $element);
        }

        // A line added to or taken from an inverse collection never makes the collection
        // itself dirty — Doctrine tracks the owning side, which is the line's own
        // reference back. The unit of work knows about it all the same.
        foreach ($uow->getScheduledEntityInsertions() as $element) {
            $this->collectElementChanges($em, $element, added: true);
        }

        foreach ($uow->getScheduledEntityDeletions() as $element) {
            $this->collectElementChanges($em, $element, added: false);
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::CREATE);

        if ($record !== null) {
            $this->pending[] = $record;
            // Registered like an update's: an owner created with its lines has one
            // record, and what the lines did belongs in it. Without this the membership
            // found no record to join and invented a second, phantom update.
            $this->pendingIndexByEntity[spl_object_id($args->getObject())] = array_key_last($this->pending);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::UPDATE);

        if ($record === null) {
            return;
        }

        $entity = $args->getObject();

        // The check has to know about them before they are folded in, in postFlush: an
        // update whose only change was inside a line of an order is still an update to
        // that order.
        if ($this->skipEmptyUpdates && !$record->hasChanges() && !$this->hasElementChanges($entity)) {
            return;
        }

        $this->pending[] = $record;
        $this->pendingIndexByEntity[spl_object_id($entity)] = array_key_last($this->pending);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::REMOVE, withChanges: false);

        if ($record !== null) {
            $this->pendingRemovals[spl_object_id($args->getObject())] = $record;
        }
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $key = spl_object_id($args->getObject());
        $record = $this->pendingRemovals[$key] ?? null;
        unset($this->pendingRemovals[$key]);

        if ($record !== null) {
            $this->pending[] = $record;
        }
    }

    /**
     * The transaction is committed: send what this flush collected.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $records = $this->pending;

        // Now that the flush is over, every element has its identifier — including the
        // ones inserted a moment ago — so what happened inside a tracked collection can
        // be named and folded into its owner's record. An owner whose own columns did
        // not change gets no postUpdate from Doctrine, there being nothing to UPDATE on
        // it, so that record is built here or nowhere.
        $em = self::entityManagerOf($args->getObjectManager());

        foreach ($this->elementsByOwner($args->getObjectManager()) as [$owner, $changes]) {
            $index = $this->pendingIndexByEntity[spl_object_id($owner)] ?? null;

            if ($index !== null && isset($records[$index])) {
                $merged = array_replace($records[$index]->changes, $changes);
                $records[$index] = $records[$index]->withChanges($this->withContext($em, $owner, $merged));

                continue;
            }

            $record = $this->recordForOwner($args->getObjectManager(), $owner, $changes);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        $this->pending = [];
        $this->pendingRemovals = [];
        $this->pendingIndexByEntity = [];
        $this->elementChanges = [];
        $this->elementMembership = [];

        // One batch: a flush that touched fifty entities is one _bulk call, not fifty round-trips.
        $this->writer->writeAll(array_values($records));
    }

    /**
     * The manager was cleared — after a failed flush, or by the application: whatever
     * was collected belongs to a flush that will not commit.
     *
     * ORM 2 can clear a single entity class while the flush that is running commits
     * the rest, and dropping the records then would lose history that did happen. But
     * a closed manager means the flush failed, and a partial clear does not change
     * that: those records describe a state the database never reached, and inventing
     * history is worse than missing it.
     */
    public function onClear(OnClearEventArgs $args): void
    {
        if (self::isPartialClear($args) && self::entityManagerOf($args->getObjectManager())?->isOpen() === true) {
            return;
        }

        // Everything a flush collected, not only the records: what onFlush saw about the
        // elements of tracked collections describes INSERTs and UPDATEs that were rolled back
        // with the rest, and would otherwise surface in the next flush as history.
        $this->pending = [];
        $this->pendingRemovals = [];
        $this->pendingIndexByEntity = [];
        $this->elementChanges = [];
        $this->elementMembership = [];
    }

    /**
     * Whether this clear names a single entity class rather than emptying the manager.
     *
     * Only ORM 2 can do that, and only ORM 2 has the method to ask, so the question is
     * put through reflection: a static analyser sees one version at a time and would
     * call any direct check redundant on ORM 2 and impossible on ORM 3. It is neither —
     * it is what tells the two versions apart, and both are supported.
     */
    private static function isPartialClear(OnClearEventArgs $args): bool
    {
        $event = new \ReflectionClass($args);

        if (!$event->hasMethod('clearsAllEntities')) {
            return false; // ORM 3: a clear is always a full one
        }

        return $event->getMethod('clearsAllEntities')->invoke($args) === false;
    }

    /**
     * Doctrine's own listeners always hand an entity manager, but the event only
     * promises an ObjectManager on the oldest supported doctrine/persistence, so the
     * narrowing is real rather than decorative: without an entity manager there is no
     * unit of work to read a change set from.
     */
    private static function entityManagerOf(ObjectManager $manager): ?EntityManagerInterface
    {
        return $manager instanceof EntityManagerInterface ? $manager : null;
    }

    /**
     * Whether this updated entity is an element of a tracked collection, and if so,
     * what changed in it — held against its owner until the owner's record is built.
     *
     * The owner is reached through the element's own side of the association, which is
     * already loaded, so this asks nothing of the database. A failure here is reported
     * like any other: an element that cannot be read must not fail the flush.
     */
    private function collectElementChanges(EntityManagerInterface $em, object $element, ?bool $added = null): void
    {
        try {
            $elementMetadata = $em->getClassMetadata($element::class);
            $changeSet = $added === null ? $em->getUnitOfWork()->getEntityChangeSet($element) : [];

            foreach ($elementMetadata->getAssociationNames() as $association) {
                if (!$elementMetadata->isSingleValuedAssociation($association) || $elementMetadata->isAssociationInverseSide($association)) {
                    continue;
                }

                $current = $elementMetadata->getFieldValue($element, $association);

                // The element changed hands. Doctrine keeps that on the owning side — the
                // element's own reference — so neither collection is dirty and, without
                // reading the change set, both owners stay silent about it.
                if (\array_key_exists($association, $changeSet) && \is_array($changeSet[$association])) {
                    $this->holdMembership($em, $element, $changeSet[$association][0] ?? null, $association, added: false);
                    $this->holdMembership($em, $element, $current, $association, added: true);

                    // Its own fields are left out of this flush on purpose: the owner it
                    // arrived at never held the value it is arriving from.
                    continue;
                }

                // A deletion answers to the owner the database row had, not to whatever
                // the object points at in memory: a line re-pointed at B and removed in
                // the same flush was deleted from A's rows, and a back-ref nulled before
                // an orphanRemoval left the removal recorded nowhere at all. The change
                // set is asked first — computing it refreshes the "original" data to the
                // current values, so for a nulled back-ref the old owner survives only
                // there — then the original data, then the object itself.
                if ($added === false) {
                    $deletedChangeSet = $em->getUnitOfWork()->getEntityChangeSet($element);
                    $owner = \array_key_exists($association, $deletedChangeSet) && \is_array($deletedChangeSet[$association])
                        ? $deletedChangeSet[$association][0] ?? null
                        : ($em->getUnitOfWork()->getOriginalEntityData($element)[$association] ?? $current);

                    $this->holdMembership($em, $element, $owner, $association, added: false);

                    continue;
                }

                $this->holdMembership($em, $element, $current, $association, $added);
            }
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, null);
        }
    }

    /**
     * A tracked collection has to be one this listener can watch: the inverse side of a
     * OneToMany, whose elements point back through a single-valued owning association.
     * That is where the unit of work reports what they did.
     *
     * A ManyToMany, the owning side, or a field that is no association at all used to be
     * accepted and then silently record nothing, which is the worst answer an audit
     * library can give. It is a mistake in a declaration, so it travels the way the other
     * declaration mistakes do: logged, or raised with the "throw" policy.
     *
     * Asked once per class — a mapping does not change while the process runs.
     */
    private function assertTrackedCollectionsAreServable(EntityManagerInterface $em, object $entity, AuditMetadata $metadata): void
    {
        $class = $entity::class;

        if (isset($this->checkedTracking[$class]) || $metadata->trackedCollections() === []) {
            return;
        }

        $classMetadata = $em->getClassMetadata($class);

        foreach ($metadata->trackedCollections() as $field) {
            $reason = match (true) {
                !$classMetadata->hasAssociation($field) => 'is not an association',
                !$classMetadata->isCollectionValuedAssociation($field) => 'is a to-one association',
                !$classMetadata->isAssociationInverseSide($field) => 'is the owning side — a ManyToMany, or a collection mapped here instead of on its elements',
                $classMetadata->getAssociationMappedByTargetField($field) === '' => 'has no mappedBy to reach its elements through',
                // The inverse side of a ManyToMany has a mappedBy and passed everything
                // above — but its elements reach back through a collection, which the
                // unit of work never reports element-by-element to this side.
                !$em->getClassMetadata($classMetadata->getAssociationTargetClass($field))
                    ->isSingleValuedAssociation($classMetadata->getAssociationMappedByTargetField($field))
                    => 'is mapped by a collection on its elements (a ManyToMany), and no element points back through a single-valued association',
                default => null,
            };

            if ($reason !== null) {
                throw new \LogicException(sprintf('%s::$%s tracks its elements, but it %s. Element tracking watches the inverse side of a OneToMany, whose elements refer back to their owner.', $class, $field, $reason));
            }
        }

        $this->checkedTracking[$class] = true;
    }

    /**
     * Holds what this element did against one owner, if that owner is audited and tracks
     * the collection this element belongs to.
     */
    private function holdMembership(EntityManagerInterface $em, object $element, mixed $owner, string $association, ?bool $added): void
    {
        if (!\is_object($owner)) {
            return; // no owner on that side: nothing to write a history against
        }

        $metadata = $this->metadataFactory->for($owner);

        if ($metadata === null) {
            return;
        }

        // An owner on its way out gets its remove; the lines going with it are not a
        // second event, and an update after a remove would be one.
        if ($em->getUnitOfWork()->isScheduledForDelete($owner)) {
            return;
        }

        $this->holdElementChanges($em, $element, $owner, $metadata, $association, $added);
    }

    private function holdElementChanges(EntityManagerInterface $em, object $element, object $owner, AuditMetadata $metadata, string $association, ?bool $added = null): void
    {
        $ownerMetadata = $em->getClassMetadata($owner::class);

        foreach ($metadata->trackedCollections() as $field) {
            $wanted = $metadata->trackedElementFields($field);

            // The tracked collection has to be the other side of the very association
            // this element points back through; a second collection of the same class,
            // mapped by another field, is not this element's home.
            if ($wanted === null
                || !$ownerMetadata->hasAssociation($field)
                || !$ownerMetadata->isAssociationInverseSide($field)
                || $ownerMetadata->getAssociationMappedByTargetField($field) !== $association
                || !$element instanceof ($ownerMetadata->getAssociationTargetClass($field))
            ) {
                continue;
            }

            $key = spl_object_id($owner);

            // An element being inserted has no identifier yet, so what it is called in
            // the record is settled after the flush; the object is what is held here.
            if ($added !== null) {
                $held = $this->elementMembership[$key][1] ?? [];
                $held[$field.'#'.spl_object_id($element)] = [
                    'element' => $element,
                    'added' => $added,
                    'field' => $field,
                    // Both are taken now, while a deleted element still has its values:
                    // Doctrine clears a generated identifier once the row is gone.
                    'value' => self::represent($element, $metadata->fields[$field] ?? null),
                    'id' => $this->identifierOf($em, $element),
                ];
                $this->elementMembership[$key] = [$owner, $held];

                continue;
            }

            $id = $this->identifierOf($em, $element);

            if ($id === null) {
                continue;
            }

            $changes = (new ChangeSetBuilder($em, $this->comparator))->elementChanges($metadata->objectType, $field, $element, $id, $wanted);

            if ($changes === []) {
                continue;
            }

            $held = $this->elementChanges[$key][1] ?? [];
            $this->elementChanges[$key] = [$owner, array_replace($held, $changes)];
        }
    }

    private function hasElementChanges(object $owner): bool
    {
        $key = spl_object_id($owner);

        return isset($this->elementChanges[$key]) || isset($this->elementMembership[$key]);
    }

    /**
     * Everything a tracked collection has to say about this flush, owner by owner:
     * fields that changed inside an element, keyed "lines.42.quantity", and elements
     * the collection gained or lost, keyed "lines.42" with one side null.
     *
     * @return list<array{0: object, 1: array<string, Change>}>
     */
    private function elementsByOwner(ObjectManager $manager): array
    {
        $em = self::entityManagerOf($manager);
        $byOwner = [];

        foreach ($this->elementChanges as $key => [$owner, $changes]) {
            $byOwner[$key] = [$owner, $changes];
        }

        foreach ($this->elementMembership as $key => [$owner, $entries]) {
            $changes = $byOwner[$key][1] ?? [];

            foreach ($entries as $entry) {
                // An inserted element had no identifier when it was collected; it has one now.
                $id = $entry['id'] ?? ($em === null ? null : $this->identifierOf($em, $entry['element']));

                if ($id === null) {
                    continue; // an element with no identifier is nothing the history can point at
                }

                $changes[$entry['field'].'.'.$id] = $entry['added']
                    ? new Change(null, $entry['value'])
                    : new Change($entry['value'], null);
            }

            $byOwner[$key] = [$owner, $changes];
        }

        return array_values($byOwner);
    }

    /**
     * @param (callable(object): mixed)|null $represent
     */
    private static function represent(object $element, ?callable $represent): mixed
    {
        return $represent === null ? null : $represent($element);
    }

    /**
     * Always-recorded context for a record whose changes arrived from tracked elements:
     * build() adds it for a record built during the flush, and a record assembled in
     * postFlush — or amended there — has to keep the same promise, that every history
     * line reads on its own.
     *
     * @param array<string, Change|mixed> $changes
     *
     * @return array<string, Change|mixed>
     */
    private function withContext(?EntityManagerInterface $em, object $owner, array $changes): array
    {
        $metadata = $em === null ? null : $this->metadataFactory->for($owner);

        if ($em === null || $metadata === null) {
            return $changes;
        }

        return (new ChangeSetBuilder($em, $this->comparator))->withAlwaysRecorded($owner, $metadata, $changes);
    }

    /**
     * The record for an owner that Doctrine never raised an event for, built after the
     * commit from what onFlush collected. The entity is still managed and its
     * identifier is settled, which is all this needs.
     *
     * @param array<string, Change> $changes
     */
    private function recordForOwner(ObjectManager $manager, object $owner, array $changes): ?AuditRecord
    {
        $record = null;

        try {
            $metadata = $this->metadataFactory->for($owner);
            $em = self::entityManagerOf($manager);

            if ($metadata === null || $em === null) {
                return null;
            }

            $id = $this->identifierOf($em, $owner);

            if ($id === null) {
                return null;
            }

            return (new AuditRecord($metadata->objectType, $id, AuditEvent::UPDATE, origin: AuditOrigin::Doctrine))
                ->withChanges($this->withContext($em, $owner, $changes));
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, $record);

            return null;
        }
    }

    private function recordFor(PostPersistEventArgs|PostUpdateEventArgs|PreRemoveEventArgs $args, string $event, bool $withChanges = true): ?AuditRecord
    {
        $entity = $args->getObject();
        $record = null;

        try {
            $metadata = $this->metadataFactory->for($entity);

            if ($metadata === null) {
                return null;
            }

            $em = self::entityManagerOf($args->getObjectManager());

            if ($em === null) {
                return null;
            }

            $this->assertTrackedCollectionsAreServable($em, $entity, $metadata);

            $id = $this->identifierOf($em, $entity);

            if ($id === null) {
                return null; // nothing to attach a history to
            }

            $record = new AuditRecord($metadata->objectType, $id, $event, origin: AuditOrigin::Doctrine);

            if ($withChanges) {
                $record = $record->withChanges((new ChangeSetBuilder($em, $this->comparator))->build($entity, $metadata));
            }

            return $record;
        } catch (\Throwable $e) {
            $this->writer->reportFailure($e, $record);

            return null;
        }
    }

    /**
     * Identifiers are ints, strings, objects with a string form (Uuid, Ulid, enums)
     * or — in a composite key — other entities, represented by their own identifier.
     */
    private function identifierOf(EntityManagerInterface $em, object $entity): int|string|null
    {
        $values = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);

        if ($values === []) {
            return null;
        }

        $parts = array_map(fn (mixed $value): string => $this->stringify($em, $value), array_values($values));

        if (\count($parts) === 1) {
            $only = reset($values);

            return \is_int($only) ? $only : $parts[0];
        }

        // A composite key, joined — and each part escaped first, or the join is ambiguous:
        // ["a|b", "c"] and ["a", "b|c"] both read as a|b|c, which is two entities sharing
        // one identity in the history. A part that holds neither "|" nor "\" is untouched,
        // so the usual "42|like" is written exactly as it always was.
        return implode('|', array_map(
            static fn (string $part): string => str_replace(['\\', '|'], ['\\\\', '\\|'], $part),
            $parts,
        ));
    }

    private function stringify(EntityManagerInterface $em, mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            \is_object($value) => (string) ($this->identifierOf($em, $value) ?? throw new \LogicException(sprintf('%s has no identifier yet and cannot be part of an audit object id.', get_debug_type($value)))),
            default => throw new \LogicException(sprintf('Cannot use a %s as an audit object id.', get_debug_type($value))),
        };
    }
}
