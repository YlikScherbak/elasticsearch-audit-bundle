<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/** A JOINED child, with a table of its own for its own columns. */
#[ORM\Entity]
class Truck extends Vehicle
{
    #[ORM\Column]
    public int $axles = 2;
}
