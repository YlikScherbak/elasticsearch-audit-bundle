<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'RackItem', schema: 'Shop')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['plain' => RackItem::class, 'boxed' => BoxedRackItem::class])]
class RackItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rack::class, inversedBy: 'items')]
    public ?Rack $rack = null;
}
