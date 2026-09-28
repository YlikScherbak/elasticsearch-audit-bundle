<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited root of a JOINED hierarchy with an owning ManyToMany of its own, which its
 * subclass inherits: one join table, whichever class of the hierarchy a row is -- and the links
 * of it are one collection's, not one per class.
 */
#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['shelf' => Shelf::class, 'corner' => CornerShelf::class])]
#[Auditable(type: 'shelf')]
class Shelf
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'shelf_label')]
    #[AuditField(represent: 'getLabel')]
    public Collection $labels;

    /**
     * Of the subclass only: a corner shelf going is a DELETE of the root's table, which does not
     * say the row was a corner shelf.
     *
     * @var Collection<int, CornerShelf>
     */
    #[ORM\ManyToMany(targetEntity: CornerShelf::class)]
    #[ORM\JoinTable(name: 'shelf_neighbour')]
    #[AuditField(represent: 'getId')]
    public Collection $neighbours;

    public function __construct()
    {
        $this->labels = new ArrayCollection();
        $this->neighbours = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
