<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadata;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

/**
 * Turns Doctrine's change set into the Changes an audit record stores.
 *
 * - a scalar field is recorded when the unit of work says it changed; two dates
 *   equal to the second are not a change (Doctrine compares objects by identity)
 * - a to-one association is recorded through its representer: old from the change
 *   set, new from the current value
 * - a to-many association is recorded when the collection is dirty, as the
 *   represented snapshot against the represented current contents
 * - an "always recorded" field appears as old == new when it did not change
 *
 * Values are read through ClassMetadata, so entities need no getters.
 */
final class ChangeSetBuilder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
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
                $old = $changeSet[$field][0] ?? null;
                $new = $changeSet[$field][1] ?? null;
                $change = self::same($old, $new) ? null : new Change($old, $new);
            } else {
                $change = null;
            }

            if ($change !== null) {
                $changes[$field] = $change;
            }
        }

        // Always-recorded fields give context to a change; they do not make one.
        // An update that touched no audited field stays empty and is skipped.
        if ($changes !== []) {
            foreach ($metadata->alwaysRecorded as $field) {
                if (!isset($changes[$field]) && !$classMetadata->hasAssociation($field)) {
                    $value = $classMetadata->getFieldValue($entity, $field);
                    $changes[$field] = new Change($value, $value);
                }
            }
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
     * Doctrine reports a "change" for every field on insert (null → value, including
     * null → null) and compares objects by identity, so two dates for the same
     * instant look changed to it. Neither is a change worth recording.
     */
    private static function same(mixed $old, mixed $new): bool
    {
        if ($old instanceof \DateTimeInterface && $new instanceof \DateTimeInterface) {
            return $old->getTimestamp() === $new->getTimestamp();
        }

        return $old === $new;
    }
}
