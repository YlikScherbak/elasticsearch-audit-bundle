<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Doctrine\ORM\Mapping as ORM;

/** A shelf's name plate: the owning side of a one-to-one, a single row and no collection. */
#[ORM\Entity]
#[ORM\Table(name: 'Plate', schema: 'Shop')]
class Plate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'plate', targetEntity: Shelf::class)]
    public ?Shelf $shelf = null;
}
