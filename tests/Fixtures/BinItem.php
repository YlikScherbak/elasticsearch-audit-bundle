<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class BinItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(referencedColumnName: 'code')]
    public ?Bin $bin = null;

    public function __construct(#[ORM\Column] public string $sku, #[ORM\Column(nullable: true)] public ?int $count = null)
    {
    }

    /** "SKU-1: unset" for a stored NULL, which is the whole point of this fixture. */
    public function getLabel(): string
    {
        return $this->sku.': '.($this->count === null ? 'unset' : (string) $this->count);
    }
}
