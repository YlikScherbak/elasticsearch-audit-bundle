<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'Label', schema: 'Shop')]
class Label
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
}
