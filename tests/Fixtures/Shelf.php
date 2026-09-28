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

    public function __construct()
    {
        $this->labels = new ArrayCollection();
    }
}
