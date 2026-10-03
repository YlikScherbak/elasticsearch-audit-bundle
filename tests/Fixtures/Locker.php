<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reference to a row keyed by an association, declared after a collection: what the row
 * memory takes of it is the member's id, read past the collection, which the row does not hold.
 */
#[ORM\Entity]
#[Auditable(type: 'locker')]
class Locker
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Not audited; of batons, which only relay teams hold otherwise. @var Collection<int, Baton> */
    #[ORM\ManyToMany(targetEntity: Baton::class)]
    #[ORM\JoinTable(name: 'locker_baton')]
    public Collection $batons;

    #[ORM\ManyToOne(targetEntity: MemberCard::class)]
    #[ORM\JoinColumn(name: 'card_member_id', referencedColumnName: 'member_id', nullable: true)]
    #[AuditField(represent: 'getLabel')]
    public ?MemberCard $card = null;

    #[ORM\Column, AuditField]
    public string $name = 'locker';

    public function __construct()
    {
        $this->batons = new ArrayCollection();
    }
}
