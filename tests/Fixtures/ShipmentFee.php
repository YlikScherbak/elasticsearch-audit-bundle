<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * Points at a shipment by a field of the same name as its lines' -- and is no line: the
 * shipment's audited collection holds {@see ShipmentLine}s, and no collection holds fees.
 */
#[ORM\Entity]
class ShipmentFee
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    public ?Shipment $shipment = null;

    #[ORM\Column]
    public int $amount = 0;
}
