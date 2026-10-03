<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapped apart from the fixtures ({@see \Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation\WhichRowsAreWatchedTest}):
 * tables in a schema, named in mixed case, a link that is history beside one that is not, a
 * collection of lines and an inverse one-to-one. Only the mapping is read; no table is made.
 */
#[ORM\Entity]
#[ORM\Table(name: 'Shelf', schema: 'Shop')]
#[Auditable(type: 'shelf')]
class Shelf
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Label> */
    #[ORM\ManyToMany(targetEntity: Label::class)]
    #[ORM\JoinTable(name: 'Shelf_Label', schema: 'Shop')]
    #[AuditField]
    public Collection $labels;

    /** @var Collection<int, Label> */
    #[ORM\ManyToMany(targetEntity: Label::class)]
    #[ORM\JoinTable(name: 'Shelf_Hidden', schema: 'Shop')]
    public Collection $hidden;

    /** @var Collection<int, ShelfLine> */
    #[ORM\OneToMany(mappedBy: 'shelf', targetEntity: ShelfLine::class)]
    #[AuditField(trackElements: true)]
    public Collection $lines;

    #[ORM\OneToOne(mappedBy: 'shelf', targetEntity: Plate::class)]
    #[AuditField]
    public ?Plate $plate = null;

    public function __construct()
    {
        $this->labels = new ArrayCollection();
        $this->hidden = new ArrayCollection();
        $this->lines = new ArrayCollection();
    }
}
