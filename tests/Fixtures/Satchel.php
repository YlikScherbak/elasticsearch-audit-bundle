<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reference to a {@see Pouch}, which is keyed by an object: its foreign key holds the key as
 * the database has it, a string, never the Sku the entity carries. A collection is declared
 * before it, so that the reference is not the first association of the mapping.
 */
#[ORM\Entity]
#[Auditable(type: 'satchel')]
class Satchel
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Sticker> */
    #[ORM\ManyToMany(targetEntity: Sticker::class)]
    #[ORM\JoinTable(name: 'satchel_sticker')]
    public Collection $stickers;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(referencedColumnName: 'id', nullable: true)]
    #[AuditField(represent: 'getContents')]
    public ?Pouch $pouch = null;

    public function __construct()
    {
        $this->stickers = new ArrayCollection();
    }
}
