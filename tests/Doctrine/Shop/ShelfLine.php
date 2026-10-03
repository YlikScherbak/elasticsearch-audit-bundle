<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A line of a shelf, with a collection of its own declared before the shelf it belongs to. */
#[ORM\Entity]
#[ORM\Table(name: 'ShelfLine', schema: 'Shop')]
class ShelfLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Label> */
    #[ORM\ManyToMany(targetEntity: Label::class)]
    #[ORM\JoinTable(name: 'ShelfLine_Label', schema: 'Shop')]
    public Collection $labels;

    #[ORM\ManyToOne(targetEntity: Shelf::class, inversedBy: 'lines')]
    public ?Shelf $shelf = null;

    public function __construct()
    {
        $this->labels = new ArrayCollection();
    }
}
