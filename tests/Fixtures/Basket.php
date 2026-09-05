<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited inverse collection declared without a representer — the mistake the
 * membership path used to answer with "null → null" instead of a declaration error.
 */
#[ORM\Entity]
#[Auditable(type: 'basket')]
class Basket
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $label;

    /** @var Collection<int, BasketItem> */
    #[ORM\OneToMany(mappedBy: 'basket', targetEntity: BasketItem::class, cascade: ['persist'])]
    #[AuditField]
    public Collection $items;

    public function __construct(string $label)
    {
        $this->label = $label;
        $this->items = new ArrayCollection();
    }

    public function add(BasketItem $item): void
    {
        $item->basket = $this;
        $this->items->add($item);
    }
}
