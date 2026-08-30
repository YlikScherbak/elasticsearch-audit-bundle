<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CrateItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(referencedColumnName: 'code')]
    public ?Crate $crate = null;

    public function __construct(#[ORM\Column] public string $sku, #[ORM\Column(nullable: true)] public ?int $quantity = 1)
    {
    }

    public function getSku(): string
    {
        return $this->sku;
    }
}
