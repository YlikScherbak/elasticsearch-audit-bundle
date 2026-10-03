<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\ShopPlate;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited inverse one-to-one beside a collection mapped by a field of the same name: apart
 * from {@see \Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop}, since ORM 3.0.0 cannot load
 * an owning one-to-one at all. Only the mapping is read.
 */
#[ORM\Entity]
#[Auditable(type: 'desk')]
class Desk
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Drawer> */
    #[ORM\OneToMany(mappedBy: 'desk', targetEntity: Drawer::class)]
    #[AuditField(trackElements: true)]
    public Collection $drawers;

    #[ORM\OneToOne(mappedBy: 'desk', targetEntity: DeskPlate::class)]
    #[AuditField]
    public ?DeskPlate $plate = null;

    public function __construct()
    {
        $this->drawers = new ArrayCollection();
    }
}
