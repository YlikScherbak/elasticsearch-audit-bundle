<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\ShopPlate;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Drawer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Desk::class, inversedBy: 'drawers')]
    public ?Desk $desk = null;
}
