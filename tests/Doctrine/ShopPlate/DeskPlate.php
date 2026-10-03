<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\ShopPlate;

use Doctrine\ORM\Mapping as ORM;

/** A desk's name plate: the owning side of a one-to-one, a single row and no element. */
#[ORM\Entity]
class DeskPlate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'plate', targetEntity: Desk::class)]
    public ?Desk $desk = null;
}
