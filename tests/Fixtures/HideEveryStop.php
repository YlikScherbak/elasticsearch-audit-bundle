<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * An ORM filter that hides every Stop, standing in for the ordinary soft-delete one.
 *
 * It exists for one question: whether a decision that needs to know what the database
 * physically holds is taken from an answer the ORM has narrowed. A filter is the cheapest
 * way to make the two disagree, and a soft-delete filter — which is what most applications
 * have — makes them disagree exactly like this.
 */
final class HideEveryStop extends SQLFilter
{
    /** @param ClassMetadata<object> $targetEntity */
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        if ($targetEntity->getName() !== Stop::class) {
            return '';
        }

        return $targetTableAlias.'.id < 0';
    }
}
