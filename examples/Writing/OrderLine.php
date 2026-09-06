<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Writing;

use Doctrine\ORM\Mapping as ORM;

/**
 * An element of a tracked collection. It carries no #[Auditable] of its own: what
 * happens to it belongs to the order's history, under `lines.<id>.quantity`.
 *
 * Declare #[Auditable] here as well and a line gets its own history too — both are
 * legitimate, and which one a screen wants is the question to answer first.
 */
#[ORM\Entity]
#[ORM\Table(name: 'example_order_line')]
class OrderLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
    public ?Order $order = null;

    #[ORM\Column]
    public string $sku;

    #[ORM\Column]
    public int $quantity;

    public function __construct(string $sku, int $quantity)
    {
        $this->sku = $sku;
        $this->quantity = $quantity;
    }

    /**
     * What the order's history calls this line. A representer is called while a
     * flush is in progress: return what is already loaded, and change nothing.
     */
    public function getSku(): string
    {
        return $this->sku;
    }
}
