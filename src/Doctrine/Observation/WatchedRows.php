<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Which rows a history is ever written about: an audited entity's, and an element's of an
 * audited collection. The row memory remembers only these, and the replay doubts only these --
 * a statement about any other row is not history, and not doubt either.
 *
 * Asked of a class alone, once, and never of every class the manager knows: a class the
 * listener refuses the declaration of is the listener's to refuse, through its failure policy,
 * and here it is simply not watched.
 */
final class WatchedRows
{
    /** @var array<string, bool> */
    private array $watched = [];

    /** @var array<string, array<string, bool>> by owner class and association */
    private array $links = [];

    /** @var array<string, list<array{0: class-string, 1: string}>> by target class */
    private array $linksTo = [];

    public function __construct(private readonly AuditMetadataFactory $audited = new AuditMetadataFactory())
    {
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function areWatched(EntityManagerInterface $em, ClassMetadata $metadata): bool
    {
        return $this->watched[$metadata->name] ??= $this->decide($em, $metadata);
    }

    /**
     * Whether an owner's links are history: an owning ManyToMany of an audited entity, among
     * its audited fields. Its join rows are the facts of that field.
     *
     * @param ClassMetadata<object> $owner
     */
    public function areLinksWatched(ClassMetadata $owner, string $association): bool
    {
        return $this->links[$owner->name][$association] ??= $this->decideLinks($owner, $association);
    }

    /**
     * The watched links a class is the target of, by owner and association: what a row of it
     * going takes out of -- the whole mapping asked once, as {@see RowBinding} asks it of every
     * statement, and kept by class.
     *
     * @param ClassMetadata<object> $target
     *
     * @return list<array{0: class-string, 1: string}>
     */
    public function linksTo(EntityManagerInterface $em, ClassMetadata $target): array
    {
        if (isset($this->linksTo[$target->name])) {
            return $this->linksTo[$target->name];
        }

        $found = [];

        foreach ($em->getMetadataFactory()->getAllMetadata() as $owner) {
            // A mapped superclass has no rows of its own: its entities' mappings carry what it
            // declares. No fixture has one -- a rule nothing here can see fail.
            if (!$owner instanceof ClassMetadata || $owner->isMappedSuperclass) {
                continue;
            }

            foreach ($owner->getAssociationNames() as $association) {
                // Declared once, on the class that declares it: a subclass inherits the mapping
                // and the join rows are the same.
                // Of the target's class or of one in its hierarchy: a row is deleted by its root's
                // table, and which class of it the row was is not in the statement.
                $of = $owner->getAssociationTargetClass($association);

                if ($owner->isInheritedAssociation($association)
                    || !is_a($target->name, $of, true) && !is_a($of, $target->name, true)
                    || !$this->areLinksWatched($owner, $association)
                ) {
                    continue;
                }

                $found[] = [$owner->name, $association];
            }
        }

        return $this->linksTo[$target->name] = $found;
    }

    /**
     * Whether a row of a class may be shown as it stood at a position ({@see HistoryReplay::copyAt()}):
     * a class some audited entity's audited association points at, to one or to many -- what a
     * representer is handed. The replay keeps the versions of these rows and of no others: a
     * flush of twenty thousand rows nothing points at keeps none.
     *
     * Every class the manager knows is asked once, the first time, and kept by root class.
     *
     * @param ClassMetadata<object> $metadata
     */
    public function areShownAsTheyStood(EntityManagerInterface $em, ClassMetadata $metadata): bool
    {
        if ($this->pointedAt === null) {
            $this->pointedAt = [];

            foreach ($em->getMetadataFactory()->getAllMetadata() as $owner) {
                if (!$owner instanceof ClassMetadata || $owner->isMappedSuperclass || $owner->getReflectionClass()->isAbstract()) {
                    continue;
                }

                try {
                    $audited = $this->audited->for($owner->newInstance());
                } catch (\Throwable) {
                    continue; // a declaration that cannot be read is the listener's to refuse
                }

                foreach ($audited === null ? [] : array_keys($audited->fields) as $field) {
                    if ($owner->hasAssociation($field)) {
                        $this->pointedAt[$em->getClassMetadata($owner->getAssociationTargetClass($field))->rootEntityName] = true;
                    }
                }
            }
        }

        return isset($this->pointedAt[$metadata->rootEntityName]);
    }

    /** @var array<string, true>|null the root classes audited associations point at, once asked */
    private ?array $pointedAt = null;

    /**
     * @param ClassMetadata<object> $owner
     */
    private function decideLinks(ClassMetadata $owner, string $association): bool
    {
        try {
            // An owning ManyToMany's: a single target is a column of the owner's own row, and an
            // inverse side's rows are the other side's -- asked outright, since on ORM 2 an
            // inverse side's mapping carries a joinTable too, an empty one. A name that is no
            // association at all throws, and is no link either.
            if ($owner->isAssociationInverseSide($association)
                || CollectionRowsQuery::entry($owner->getAssociationMapping($association), 'joinTable') === null
            ) {
                return false;
            }

            $audited = $this->audited->for($owner->newInstance());

            return $audited !== null && \array_key_exists($association, $audited->fields);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function decide(EntityManagerInterface $em, ClassMetadata $metadata): bool
    {
        try {
            if ($this->audited->for($metadata->newInstance()) !== null) {
                return true;
            }

            foreach ($metadata->getAssociationNames() as $association) {
                if (!$metadata->isSingleValuedAssociation($association)) {
                    continue;
                }

                $owner = $em->getClassMetadata($metadata->getAssociationTargetClass($association));
                $audited = $this->audited->for($owner->newInstance());

                foreach ($audited === null ? [] : array_keys($audited->fields) as $field) {
                    // Only the inverse side names the field it is mapped by, and asking an
                    // owning ManyToMany threw -- caught below as "not watched", so a class was
                    // left unwatched by whichever of its owner's collections came first.
                    if ($owner->hasAssociation($field)
                        && $owner->isCollectionValuedAssociation($field)
                        && $owner->isAssociationInverseSide($field)
                        && $owner->getAssociationMappedByTargetField($field) === $association
                    ) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
