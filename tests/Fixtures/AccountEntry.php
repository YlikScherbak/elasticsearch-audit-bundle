<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A line of a {@see MemberAccount}, with a collection of its own declared before its owner. */
#[ORM\Entity]
class AccountEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Not audited. @var Collection<int, Baton> */
    #[ORM\ManyToMany(targetEntity: Baton::class)]
    #[ORM\JoinTable(name: 'account_entry_baton')]
    public Collection $batons;

    #[ORM\ManyToOne(targetEntity: MemberAccount::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'account_member_id', referencedColumnName: 'member_id')]
    public ?MemberAccount $account = null;

    public function __construct(
        #[ORM\Column]
        public string $memo,
        #[ORM\Column]
        public int $amount = 0,
    ) {
        $this->batons = new ArrayCollection();
    }

    public function getMemo(): string
    {
        return $this->memo;
    }
}
