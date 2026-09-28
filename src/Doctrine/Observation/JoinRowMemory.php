<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Doctrine\ORM\EntityManagerInterface;

/**
 * What each owner's rows of an owning ManyToMany held, taken from the join table and moving
 * with the log -- the account a link's facts are told against.
 *
 * **Where an owner's first account comes from.** The join table, read through the connection
 * the first time a flush is about to touch the owner's collection -- in onFlush, before the
 * statement that changes it -- and never Doctrine's snapshot of the collection: that is the
 * membership as of the last time Doctrine synchronised it, and it believes a link the log took
 * back is still there. A target about to go is the other road: the owners that hold it are
 * read, each with everything it holds, so that it going is the move of each whole list.
 *
 * **Where the account stands.** Each is taken where the log stood when it was read, and with
 * the positions of the statements of those rows that had run by then and not been undone --
 * what the account already holds. Replayed from where the log was settled, those are undone
 * from it first and the ones that stayed done are carried out again, so that one of them taken
 * back after the account was read leaves it, rather than staying in it as where the rows
 * started from.
 *
 * **When it is forgotten.** At settling, like a row: an account whose owner nothing holds any
 * more goes, and so does one the replay could not follow -- which is read again the next time
 * it is needed, rather than believed.
 */
final class JoinRowMemory
{
    /**
     * By association -- the owner's root class and the association's name -- and owner.
     *
     * @var array<string, array<string, array{owner: array<string, mixed>, targets: array<string, array<string, mixed>>, takenAt: int, includes: list<int>}>>
     */
    private array $links = [];

    /** @var array<string, array<string, \WeakReference<object>|null>> the owner each account is held by, when one was at hand */
    private array $objects = [];

    /** @var array<string, array<string, int>> by association: the targets whose holders were read, and where the log stood then */
    private array $holdersRead = [];

    /** How many questions it has asked the database, for the tests of what it costs. */
    private int $asked = 0;

    public function __construct(private readonly StatementLog $log)
    {
    }

    /** The association an account is kept under: its owner's root class and its name. */
    public static function associationOf(string $root, string $association): string
    {
        return $root.'::'.$association;
    }

    /**
     * An owner's links, the first time a flush is about to touch them; an owner already
     * accounted for keeps its account. An owner not inserted yet holds nothing, and costs no
     * question.
     *
     * @param \Closure(string, string): list<int> $includes the positions of the statements of an
     *                                                      owner's rows that have run and not been
     *                                                      undone, by association and owner
     */
    public function rememberTheLinksOf(EntityManagerInterface $em, object $owner, string $association, \Closure $includes): void
    {
        $metadata = $em->getClassMetadata($owner::class);
        $root = $em->getClassMetadata($metadata->rootEntityName);
        $key = RowMemory::keyColumns($em, $owner);

        if ($key === null || $key === []) {
            return; // no row yet: its INSERT, and its links', will say what it holds
        }

        $of = self::associationOf($root->name, $association);
        $id = HistoryReplay::keyOf($root, $key);

        if (isset($this->links[$of][$id])) {
            if (($this->objects[$of][$id] ?? null)?->get() !== $owner) {
                $this->objects[$of][$id] = \WeakReference::create($owner);
            }

            return;
        }

        $targets = [];

        if (!$em->getUnitOfWork()->isScheduledForInsert($owner)) {
            $query = JoinRowsQuery::linksOf($em->getConnection()->getDatabasePlatform(), $metadata->getAssociationMapping($association), $key);

            if ($query === null) {
                return; // the mapping does not say: nothing is known, and nothing is guessed
            }

            ++$this->asked;
            $target = $em->getClassMetadata($em->getClassMetadata($metadata->getAssociationTargetClass($association))->rootEntityName);

            foreach ($em->getConnection()->fetchAllNumeric($query['sql'], $query['params']) as $values) {
                $targetKey = array_combine($query['targets'], $values);
                $targets[HistoryReplay::keyOf($target, $targetKey)] = $targetKey;
            }
        }

        $this->links[$of][$id] = ['owner' => $key, 'targets' => $targets, 'takenAt' => $this->log->position(), 'includes' => $includes($of, $id)];
        $this->objects[$of][$id] = \WeakReference::create($owner);
    }

    /**
     * The owners holding a target that is about to go, each with everything it holds -- one
     * question, whatever the number of owners. An owner already accounted for keeps its
     * account; one read here is held by nothing, and goes at settling.
     *
     * @param class-string                       $ownerClass
     * @param \Closure(string, string): list<int> $includes   as for {@see rememberTheLinksOf()}
     */
    public function rememberTheHoldersOf(EntityManagerInterface $em, object $target, string $ownerClass, string $association, \Closure $includes): void
    {
        $ownerMetadata = $em->getClassMetadata($ownerClass);
        $root = $em->getClassMetadata($ownerMetadata->rootEntityName);
        $targetRoot = $em->getClassMetadata($em->getClassMetadata($target::class)->rootEntityName);
        $targetKey = RowMemory::keyColumns($em, $target);

        if ($targetKey === null || $targetKey === []) {
            return; // not a row yet: nothing can hold it
        }

        $of = self::associationOf($root->name, $association);
        $targetId = HistoryReplay::keyOf($targetRoot, $targetKey);

        if (isset($this->holdersRead[$of][$targetId])) {
            return;
        }

        $query = JoinRowsQuery::holdersOf($em->getConnection()->getDatabasePlatform(), $ownerMetadata->getAssociationMapping($association), $targetKey);

        if ($query === null) {
            return;
        }

        ++$this->asked;
        $held = [];
        $owners = \count($query['owners']);

        foreach ($em->getConnection()->fetchAllNumeric($query['sql'], $query['params']) as $values) {
            $ownerKey = array_combine($query['owners'], \array_slice($values, 0, $owners));
            $heldKey = array_combine($query['targets'], \array_slice($values, $owners));
            $id = HistoryReplay::keyOf($root, $ownerKey);
            // The id kept beside the key: a key of digits is an integer to an array.
            $held[$id]['id'] = $id;
            $held[$id]['owner'] = $ownerKey;
            $held[$id]['targets'][HistoryReplay::keyOf($targetRoot, $heldKey)] = $heldKey;
        }

        foreach ($held as ['id' => $id, 'owner' => $ownerKey, 'targets' => $targets]) {
            if (!isset($this->links[$of][$id])) {
                $this->links[$of][$id] = ['owner' => $ownerKey, 'targets' => $targets, 'takenAt' => $this->log->position(), 'includes' => $includes($of, $id)];
                $this->objects[$of][$id] ??= null;
            }
        }

        $this->holdersRead[$of][$targetId] = $this->log->position();
    }

    /**
     * @return array<string, array<string, array{owner: array<string, mixed>, targets: array<string, array<string, mixed>>, takenAt: int, includes: list<int>}>>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function holdersRead(): array
    {
        return $this->holdersRead;
    }

    /**
     * Folds what the replay says the rows hold into the accounts, once nothing in the log can
     * still be rolled back: an account from here on, with nothing in it to undo. What the
     * replay could not follow is let go, and so is what nothing holds.
     *
     * @param array<string, array<string, array<string, array<string, mixed>>|null>> $states what each owner's rows hold where the
     *                                                                                     log is settled, null where it is not known
     */
    public function settle(array $states, int $at): void
    {
        $links = [];
        $objects = [];

        foreach ($states as $of => $owners) {
            foreach ($owners as $id => $targets) {
                $object = $this->objects[$of][$id] ?? null;

                $owner = $this->links[$of][$id]['owner'] ?? null;

                if ($targets === null || $object?->get() === null || $owner === null) {
                    continue;
                }

                $links[$of][$id] = ['owner' => $owner, 'targets' => $targets, 'takenAt' => $at, 'includes' => []];
                $objects[$of][$id] = $object;
            }
        }

        $this->links = $links;
        $this->objects = $objects;
        $this->holdersRead = [];
    }

    public function asked(): int
    {
        return $this->asked;
    }

    /** How many accounts are held, for the tests that pin what it costs. */
    public function size(): int
    {
        return array_sum(array_map('count', $this->links));
    }
}
