<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * Not audited in its own right: its history belongs to the shipment that owns it.
 */
#[ORM\Entity]
class ShipmentLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    public ?Shipment $shipment = null;

    #[ORM\Column]
    public string $product;

    #[ORM\Column]
    public int $quantity;

    public function __construct(string $product, int $quantity)
    {
        $this->product = $product;
        $this->quantity = $quantity;
    }

    public function getLabel(): string
    {
        return $this->product;
    }
}
