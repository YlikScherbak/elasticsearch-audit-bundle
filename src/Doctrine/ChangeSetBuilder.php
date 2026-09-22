<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

use Borsche\ElasticsearchAuditBundle\Exception\DeclarationMistake;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadata;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

/**
 * Turns Doctrine's change set into the Changes an audit record stores.
 *
 * - a scalar field is recorded when the unit of work says it changed, unless the
 *   comparators call the two sides the same value — Doctrine compares objects by
 *   identity, so two dates for the same instant look changed to it
 * - a to-one association is recorded through its representer: old from the change
 *   set, new from the current value
 * - a to-many association is recorded when the collection is dirty, as the
 *   represented snapshot against the represented current contents
 * - an "always recorded" field appears as old == new when it did not change
 *
 * Values are read through ClassMetadata, so entities need no getters.
 *
 * @internal used by AuditSubscriber while a flush is running
 */
final class ChangeSetBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValueComparatorInterface $comparator = new ValueComparator(),
    ) {
    }

    /**
     * @param array<string, mixed>|null   $changeSet
     * @param array<string, list<object>> $emptied   what an owning collection held before this flush
     *                                               emptied it, by field: the one source left once
     *                                               clear() has taken its own empty snapshot
     * @param array<string, mixed>        $asFlushed what the always-recorded fields held when the
     *                                               flush began, for the ones it did not write Doctrine's change set, when the caller
     *                                             already holds one; read from the unit of
     *                                             work when null
     *
     * @return array<string, Change>
     */
    public function build(object $entity, AuditMetadata $metadata, ?array $changeSet = null, array $emptied = [], array $asFlushed = []): array
    {
        $classMetadata = $this->em->getClassMetadata($entity::class);

        // The caller may hand in the change set it captured earlier: by postUpdate the
        // unit of work may no longer have it. See AuditSubscriber::changeSetFor().
        $changeSet ??= $this->em->getUnitOfWork()->getEntityChangeSet($entity);
        $changes = [];

        foreach ($metadata->fields as $field => $represent) {
            if ($classMetadata->isCollectionValuedAssociation($field)) {
                // Only an owning collection is compared as a whole. An inverse one is
                // not what Doctrine persists — the element's own reference back is —
                // so it can be dirty in memory while the database keeps nothing, and it
                // is dirty in memory whenever an element was added properly, which the
                // membership path already records. Comparing it here told the same
                // change twice, and invented one for a relation that was never saved.
                // A collection the flush is emptying answers from what it held, which
                // the listener took before this flush could destroy it: clear() leaves an
                // empty snapshot and a collection that says it is not dirty, and a
                // replaced collection leaves a new one whose snapshot never held the old
                // members. Both produced a record that said nothing while the join rows
                // were deleted, or one whose "old" side was empty.
                $change = match (true) {
                // The emptying comes first, including for an inverse side. The skip
                // below is right about an inverse collection in general -- what the
                // database took is the element's own reference back, and the
                // membership path records that -- but an emptying is the case where
                // the membership path may have nothing to record: replacing an inverse
                // collection that has orphanRemoval deletes its rows with one statement
                // and no lifecycle event at all. Ordered the other way round, the arm
                // this branch exists for was never reached, and a replaced collection
                // came back as an update with nothing in it while its rows were gone.
                // The caller drops this whole-collection form when the elements did
                // speak, so the two cannot both describe one emptying.
                    \array_key_exists($field, $emptied) => new Change(
                        self::representAll($emptied[$field], $represent),
                        self::representAll(self::contentsOf($classMetadata->getFieldValue($entity, $field)), $represent),
                    ),
                    $classMetadata->isAssociationInverseSide($field) => null,
                    default => $this->collectionChange($classMetadata->getFieldValue($entity, $field), $represent),
                };
            } elseif ($classMetadata->isSingleValuedAssociation($field)) {
                // Both sides out of the change set, like a scalar. The new side used to be
                // read off the entity, which is not the same thing after postUpdate: a
                // listener that reassigns the association there changes nothing in the
                // database — Doctrine documents post events as irrelevant to that flush's
                // persistence — and the record then named a related object the row does
                // not point at.
                $change = \array_key_exists($field, $changeSet) && \is_array($changeSet[$field])
                    ? new Change(self::represent($changeSet[$field][0] ?? null, $represent), self::represent($changeSet[$field][1] ?? null, $represent))
                    : null;
            } elseif (\array_key_exists($field, $changeSet) && \is_array($changeSet[$field])) {
                $change = new Change($changeSet[$field][0] ?? null, $changeSet[$field][1] ?? null);
            } else {
                $change = null;
            }

            // One decision, whatever kind of field it was: a scalar, an association and
            // a collection all answer "did this change" the same way, and the
            // application can override that answer for any of them.
            if ($change !== null && $this->unchanged($metadata->objectType, $field, $change->old, $change->new)) {
                $change = null;
            }

            if ($change !== null) {
                $changes[$field] = $change;
            }
        }

        return $this->withAlwaysRecorded($entity, $metadata, $changes, $asFlushed, $changeSet);
    }

    /**
     * Always-recorded fields give context to a change; they do not make one. An update
     * that touched no audited field stays empty and is skipped — which is why this runs
     * only on a non-empty set, and why the listener calls it again for a record whose
     * only changes arrived from tracked elements after the flush: "every history line
     * reads on its own" has to hold for those too.
     *
     * @param array<string, Change|mixed> $changes
     * @param array<string, mixed>        $asFlushed what these fields held when the flush began
     * @param array<string, mixed>        $changeSet what the flush wrote, where it wrote them
     *
     * @return array<string, Change|mixed>
     */
    public function withAlwaysRecorded(object $entity, AuditMetadata $metadata, array $changes, array $asFlushed = [], array $changeSet = []): array
    {
        if ($changes === [] || $metadata->alwaysRecorded === []) {
            return $changes;
        }

        $classMetadata = $this->em->getClassMetadata($entity::class);

        foreach ($metadata->alwaysRecorded as $field) {
            if (isset($changes[$field]) || $classMetadata->hasAssociation($field)) {
                continue;
            }

            // The value the row holds, which is not always the value the object holds.
            //
            // In order: what the flush wrote, when this field was part of it — that
            // covers a preUpdate listener correcting it, because Doctrine recomputes the
            // change set for exactly that; then what the field held when the flush began,
            // which is what the row kept if the flush did not write it; and the live
            // object last, for a record built outside a flush.
            //
            // Reading the object first was the mistake: a postUpdate listener that
            // touches the entity changes nothing in the database, and the context beside
            // the change then described a state nobody can find.
            $value = match (true) {
                \array_key_exists($field, $changeSet) && \is_array($changeSet[$field]) => $changeSet[$field][1] ?? null,
                \array_key_exists($field, $asFlushed) => $asFlushed[$field],
                default => $classMetadata->getFieldValue($entity, $field),
            };

            $changes[$field] = new Change($value, $value);
        }

        return $changes;
    }

    /**
     * What changed inside one element of a tracked collection, keyed
     * "collection.elementId.field" — "lines.42.quantity".
     *
     * Associations of the element are left out: representing one needs a callable, and
     * an element has nowhere to declare it. The comparator is asked about
     * "collection.field", without the id, because a rule about quantities is about
     * quantities and not about element 42.
     *
     * @param bool|list<string>         $wanted    true for every field of the element that changed
     * @param array<string, mixed>|null $changeSet what changed in the element, when the caller
     *                                             knows better than the unit of work does
     *
     * @return array<string, Change>
     */
    public function elementChanges(string $objectType, string $collectionField, object $element, int|string $elementId, bool|array $wanted, ?array $changeSet = null): array
    {
        $classMetadata = $this->em->getClassMetadata($element::class);
        $changes = [];

        // The caller's change set when it has one. It is what the unit of work said,
        // with the old side of anything a preUpdate listener corrected taken from before
        // the correction - which the unit of work itself no longer knows.
        foreach ($changeSet ?? $this->em->getUnitOfWork()->getEntityChangeSet($element) as $field => $sides) {
            if (!\is_array($sides) || $classMetadata->hasAssociation($field)) {
                continue;
            }

            if (\is_array($wanted) && !\in_array($field, $wanted, true)) {
                continue;
            }

            $change = new Change($sides[0] ?? null, $sides[1] ?? null);

            if ($this->unchanged($objectType, $collectionField.'.'.$field, $change->old, $change->new)) {
                continue;
            }

            $changes[ElementKey::field($collectionField, $elementId, $field)] = $change;
        }

        return $changes;
    }

    /**
     * @param (callable(object): mixed)|null $represent
     */
    /**
     * Each element as the history should show it.
     *
     * @param list<object>                 $elements
     * @param (callable(object): mixed)|null $represent
     *
     * @return list<mixed>
     */
    private static function representAll(array $elements, ?callable $represent): array
    {
        return array_values(array_map(static fn (object $element): mixed => self::represent($element, $represent), $elements));
    }

    /**
     * What a to-many field holds right now, whatever kind of collection it is.
     *
     * @return list<object>
     */
    private static function contentsOf(mixed $collection): array
    {
        if (!$collection instanceof Collection) {
            return [];
        }

        return array_values(array_filter($collection->toArray(), static fn (mixed $element): bool => \is_object($element)));
    }

    private function collectionChange(mixed $collection, ?callable $represent): ?Change
    {
        if (!$collection instanceof PersistentCollection || !$collection->isDirty()) {
            return null;
        }

        // Adding to a lazy collection does not load it, so its snapshot would be
        // empty and the record would claim every element is new. Loading it here
        // costs one query — only for dirty, audited collections.
        if (!$collection->isInitialized()) {
            $collection->initialize();
        }

        $map = static fn (iterable $items): array => array_values(array_map(
            static fn (object $item): mixed => self::represent($item, $represent),
            \is_array($items) ? $items : iterator_to_array($items, false),
        ));

        return new Change($map($collection->getSnapshot()), $map($collection));
    }

    /**
     * @param (callable(object): mixed)|null $represent
     */
    private static function represent(mixed $related, ?callable $represent): mixed
    {
        if ($related === null) {
            return null;
        }

        if ($represent === null) {
            throw new DeclarationMistake(sprintf('An audited association needs a representer (a callable turning %s into what to store).', get_debug_type($related)));
        }

        return $represent($related);
    }

    /**
     * Whether the two sides count as the same value, and so as no change at all.
     *
     * The application answers first: what "unchanged" means is a property of the data,
     * not of Doctrine. A datetime_timezone column compared by instant reports a change
     * whenever the zone moves, and the record then shows two timestamps that read
     * identically — a comparator says "by wall clock here" and the record is not
     * written. The same comparators decide what a frame drops when it closes, so a rule
     * is expressed once and holds on both paths.
     */
    private function unchanged(string $objectType, string $field, mixed $old, mixed $new): bool
    {
        // The fallback is the chain's own, not a second opinion written here: two answers
        // to "did this move" is one too many, and they had drifted apart — an array
        // holding two dates for the same instant was unchanged to one and changed to the
        // other, so a collection snapshot said different things depending on whether a
        // comparator had been injected.
        return $this->comparator->equals($objectType, $field, $old, $new) ?? ValueComparator::same($old, $new);
    }
}
