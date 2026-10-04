<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

use Borsche\ElasticsearchAuditBundle\Exception\DeclarationMistake;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadata;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns the sides of what a row's statement changed into the Changes an audit record stores.
 *
 * - a scalar field is recorded when its sides differ, unless the comparators call them
 *   the same value — two dates for the same instant, say
 * - a to-one association is recorded through its representer, both sides as the caller
 *   hands them in
 * - a to-many association is not this: an owning collection's history is its join rows'
 *   ({@see Observation\LinkRuns}), and what happened inside one its elements' rows'
 *   ({@see Observation\ElementFieldRuns})
 * - an "always recorded" field appears as old == new when it did not change
 *
 * The sides are read from the connection by the caller, never from Doctrine's change set, which
 * a flush nested inside another empties, fills from a refused flush, or describes as it was
 * planned rather than as it was written.
 *
 * @internal used by the history built from the connection's log
 */
final class ChangeSetBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValueComparatorInterface $comparator = new ValueComparator(),
    ) {
    }

    /**
     * @param array<string, mixed> $changeSet what the statement changed, by field: [old, new], the
     *                                        sides as the row held them -- required, and never
     *                                        read from the unit of work
     *
     * @return array<string, Change>
     */
    public function build(object $entity, AuditMetadata $metadata, array $changeSet): array
    {
        $classMetadata = $this->em->getClassMetadata($entity::class);
        $changes = [];

        foreach ($metadata->fields as $field => $represent) {
            if ($classMetadata->isCollectionValuedAssociation($field)) {
                continue; // the rows' own history, not a row's field
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
     * @param array<string, mixed>        $asFlushed what these fields held when the flush began
     *
     * @return array<string, Change|mixed>
     */
    public function withAlwaysRecorded(object $entity, AuditMetadata $metadata, array $changes, array $asFlushed = []): array
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
            // What the field held where the record stands -- the row's, read from the
            // statements -- and the live object only for a record built outside a flush.
            //
            // Reading the object first was the mistake: a postUpdate listener that
            // touches the entity changes nothing in the database, and the context beside
            // the change then described a state nobody can find.
            $value = \array_key_exists($field, $asFlushed) ? $asFlushed[$field] : $classMetadata->getFieldValue($entity, $field);

            $changes[$field] = new Change($value, $value);
        }

        return $changes;
    }

    /**
     * One field of one element, as a statement changed it: its key and the change, or null
     * when the declaration does not watch that field or the comparator says nothing moved.
     *
     * The sides are what the row held and what the statement wrote, both through the
     * column's type -- read from the connection, not from Doctrine's change set, which a
     * flush nested inside another empties, fills from a refused flush, or describes as it
     * was planned rather than as it was written.
     *
     * The comparator is asked about "collection.field", without the id, because a rule
     * about quantities is about quantities and not about element 42.
     *
     * @param bool|list<string> $wanted the element fields the collection watches; true for all of them
     *
     * @return array{0: string, 1: Change}|null
     */
    public function elementFieldChange(string $objectType, string $collectionField, int|string $elementId, string $field, mixed $old, mixed $new, bool|array $wanted): ?array
    {
        if (\is_array($wanted) && !\in_array($field, $wanted, true)) {
            return null;
        }

        if ($this->unchanged($objectType, $collectionField.'.'.$field, $old, $new)) {
            return null;
        }

        return [ElementKey::field($collectionField, $elementId, $field), new Change($old, $new)];
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
