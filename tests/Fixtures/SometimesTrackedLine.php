<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * The element of SometimesMisspelledTracking's collection: its own owner, so the two
 * fixtures do not borrow Shipment's mapping to say something about a different case.
 */
#[ORM\Entity]
class SometimesTrackedLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    public ?SometimesMisspelledTracking $owner = null;

    #[ORM\Column]
    public int $quantity;

    public function __construct(int $quantity)
    {
        $this->quantity = $quantity;
    }
}
