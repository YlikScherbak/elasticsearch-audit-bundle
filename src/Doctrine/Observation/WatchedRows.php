<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

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
