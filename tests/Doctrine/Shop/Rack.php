<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Holds the root of a hierarchy: a boxed item, a subclass, is one of its elements too. */
#[ORM\Entity]
#[ORM\Table(name: 'Rack', schema: 'Shop')]
#[Auditable(type: 'rack')]
class Rack
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, RackItem> */
    #[ORM\OneToMany(mappedBy: 'rack', targetEntity: RackItem::class)]
    #[AuditField(trackElements: true)]
    public Collection $items;

    /** Bays, a target keyed by two columns. @var Collection<int, Bay> */
    #[ORM\ManyToMany(targetEntity: Bay::class)]
    #[ORM\JoinTable(name: 'Rack_Bay', schema: 'Shop')]
    #[ORM\JoinColumn(name: 'rack_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'bay_code', referencedColumnName: 'code')]
    #[ORM\InverseJoinColumn(name: 'bay_aisle', referencedColumnName: 'aisle')]
    #[AuditField]
    public Collection $bays;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->bays = new ArrayCollection();
    }
}
