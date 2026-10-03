<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An owner keyed by an association, its member: its entries' foreign key holds the member's id,
 * and an entry belongs to it by that value.
 */
#[ORM\Entity]
#[Auditable(type: 'member_account')]
class MemberAccount
{
    /** @var Collection<int, AccountEntry> */
    #[ORM\OneToMany(mappedBy: 'account', targetEntity: AccountEntry::class)]
    #[AuditField(represent: 'getMemo', trackElements: ['amount'])]
    public Collection $entries;

    public function __construct(
        // A to-one rather than a one-to-one: ORM 3.0 cannot load an identifier mapped as one.
        #[ORM\Id, ORM\ManyToOne(targetEntity: Member::class)]
        public Member $member,
    ) {
        $this->entries = new ArrayCollection();
    }
}
