<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Doctrine\ORM\Mapping as ORM;

/** In a bay, by a foreign key of the bay's two columns. */
#[ORM\Entity]
#[ORM\Table(name: 'Slot', schema: 'Shop')]
class Slot
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Bay::class)]
    #[ORM\JoinColumn(name: 'bay_code', referencedColumnName: 'code')]
    #[ORM\JoinColumn(name: 'bay_aisle', referencedColumnName: 'aisle')]
    public ?Bay $bay = null;
}
