<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * A key that is an association -- Doctrine's derived identity: the card's id is its member's.
 * A foreign key to a card holds the member's id.
 */
#[ORM\Entity]
class MemberCard
{
    public function __construct(
        // A to-one rather than a one-to-one: ORM 3.0 cannot load an identifier mapped as one.
        #[ORM\Id, ORM\ManyToOne(targetEntity: Member::class)]
        public Member $member,
        #[ORM\Column]
        public string $label,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
