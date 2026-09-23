<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * An SQL filter over the very rows a history is being checked against.
 *
 * {@see HideEveryStop} does the same to Stops, and a search whose world is made of
 * Articles, Crates and CrateItems can turn it on all day without a single row of its own
 * being hidden. A filter is only a test of anything when it covers what is under test:
 * this one hides every line, which is the ordinary soft-delete shape put at its most
 * extreme, and it is what makes "asked underneath the application's filters" a claim the
 * search can break rather than a sentence in a comment.
 */
final class HideEveryLine extends SQLFilter
{
    /**
     * The alias is untyped, as in {@see HideEveryStop}: ORM 2 declares it without a type,
     * and a subclass may not narrow it, while ORM 3 lets a subclass widen it. Typed, this
     * fixture was a fatal error on the oldest supported ORM, which takes every Doctrine
     * test with it because the attribute driver loads the whole directory.
     *
     * @param ClassMetadata<object> $targetEntity
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        return $targetEntity->getName() === CrateItem::class ? '1 = 0' : '';
    }
}
