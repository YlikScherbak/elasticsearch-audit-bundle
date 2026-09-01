<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadata;
use Borsche\ElasticsearchAuditBundle\Model\Change;
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
     * @return array<string, Change>
     */
    public function build(object $entity, AuditMetadata $metadata): array
    {
        $classMetadata = $this->em->getClassMetadata($entity::class);
        $changeSet = $this->em->getUnitOfWork()->getEntityChangeSet($entity);
        $changes = [];

        foreach ($metadata->fields as $field => $represent) {
            if ($classMetadata->isCollectionValuedAssociation($field)) {
                $change = $this->collectionChange($classMetadata->getFieldValue($entity, $field), $represent);
            } elseif ($classMetadata->isSingleValuedAssociation($field)) {
                $change = \array_key_exists($field, $changeSet) && \is_array($changeSet[$field])
                    ? new Change(self::represent($changeSet[$field][0] ?? null, $represent), self::represent($classMetadata->getFieldValue($entity, $field), $represent))
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

        return $this->withAlwaysRecorded($entity, $metadata, $changes);
    }

    /**
     * Always-recorded fields give context to a change; they do not make one. An update
     * that touched no audited field stays empty and is skipped — which is why this runs
     * only on a non-empty set, and why the listener calls it again for a record whose
     * only changes arrived from tracked elements after the flush: "every history line
     * reads on its own" has to hold for those too.
     *
     * @param array<string, Change|mixed> $changes
     *
     * @return array<string, Change|mixed>
     */
    public function withAlwaysRecorded(object $entity, AuditMetadata $metadata, array $changes): array
    {
        if ($changes === [] || $metadata->alwaysRecorded === []) {
            return $changes;
        }

        $classMetadata = $this->em->getClassMetadata($entity::class);

        foreach ($metadata->alwaysRecorded as $field) {
            if (!isset($changes[$field]) && !$classMetadata->hasAssociation($field)) {
                $value = $classMetadata->getFieldValue($entity, $field);
                $changes[$field] = new Change($value, $value);
            }
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
     * @param bool|list<string> $wanted true for every field of the element that changed
     *
     * @return array<string, Change>
     */
    public function elementChanges(string $objectType, string $collectionField, object $element, int|string $elementId, bool|array $wanted): array
    {
        $classMetadata = $this->em->getClassMetadata($element::class);
        $changes = [];

        foreach ($this->em->getUnitOfWork()->getEntityChangeSet($element) as $field => $sides) {
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

            $changes[$collectionField.'.'.$elementId.'.'.$field] = $change;
        }

        return $changes;
    }

    /**
     * @param (callable(object): mixed)|null $represent
     */
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
            throw new \LogicException(sprintf('An audited association needs a representer (a callable turning %s into what to store).', get_debug_type($related)));
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
