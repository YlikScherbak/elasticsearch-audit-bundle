<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The objects the application removed, by the row they were, until the history that may name
 * them has been written.
 *
 * A record that says an article left its author names the author through its representer, and
 * the author may be gone by then: removed by the same flush, and published late, after a
 * clear. For a class whose rows the listener watches, the replay has the row as it stood before
 * its DELETE ({@see HistoryReplay::asItStoodBeforeItWent()}); for any other, what is left is
 * the object the application held -- and so what the representer is shown is the object, as
 * the application left it, not the row: a field it changed and never wrote is what it says.
 * The bundle reads the rows of watched classes only, by contract.
 *
 * Held strongly, and only as long as it may be asked for: by the row's key taken before the
 * DELETE, which clears a generated identifier on the object; let go of once the statement that
 * took the row has been read into the history, and -- when a flush's state is forgotten
 * without anything being kept for the next -- if it never ran at all.
 *
 * @internal the listener's, for its reader of the log
 */
final class DepartedObjects
{
    /** @var array<string, array{object: object, at: int|null}> by root class and row key */
    private array $objects = [];

    /** At preRemove: the object, under the key its row has now. */
    public function leaving(EntityManagerInterface $em, object $entity): void
    {
        $key = RowMemory::keyColumns($em, $entity);

        if ($key === null) {
            return; // never a row: nothing will name it
        }

        $root = $em->getClassMetadata($em->getClassMetadata($entity::class)->rootEntityName);
        $this->objects[$root->name.'|'.HistoryReplay::keyOf($root, $key)] = ['object' => $entity, 'at' => null];
    }

    /** At postRemove: its row was taken by the statement at this position. */
    public function gone(object $entity, int $at): void
    {
        foreach ($this->objects as $name => $departed) {
            if ($departed['object'] === $entity) {
                $this->objects[$name]['at'] = $at;
            }
        }
    }

    /**
     * The object that was the row, if the application removed it.
     *
     * @param class-string         $class a class of the row's hierarchy
     * @param array<string, mixed> $key   its identifier columns and their database values
     */
    public function find(EntityManagerInterface $em, string $class, array $key): ?object
    {
        $root = $em->getClassMetadata($em->getClassMetadata($class)->rootEntityName);

        return $this->objects[$root->name.'|'.HistoryReplay::keyOf($root, $key)]['object'] ?? null;
    }

    /**
     * @param bool $keepingTheUnwritten whether a removal whose DELETE has not run is kept: it is,
     *                                  when what is forgotten is a flush found behind the next
     *                                  one, and the removal belongs to that next one
     */
    public function forgetThrough(int $readThrough, bool $keepingTheUnwritten): void
    {
        foreach ($this->objects as $name => $departed) {
            if ($departed['at'] === null ? !$keepingTheUnwritten : $departed['at'] <= $readThrough) {
                unset($this->objects[$name]);
            }
        }
    }

    /** How many are held, for the tests that pin that it does not grow. */
    public function size(): int
    {
        return \count($this->objects);
    }
}
