<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Holds a subclass, mapped by a field its root declares: the root's rows hold the elements. */
#[ORM\Entity]
#[ORM\Table(name: 'Cart', schema: 'Shop')]
#[Auditable(type: 'cart')]
class Cart
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, HeavyLoad> */
    #[ORM\OneToMany(mappedBy: 'cart', targetEntity: HeavyLoad::class)]
    #[AuditField(trackElements: true)]
    public Collection $heavy;

    public function __construct()
    {
        $this->heavy = new ArrayCollection();
    }
}
