<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A mapped superclass that can be made and declares itself audited: asked of an instance, it
 * answers as its entity ({@see BadgeBoard}) does -- and still has no rows of its own.
 */
#[ORM\MappedSuperclass]
#[Auditable(type: 'badged')]
class Badged
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Sticker> */
    #[ORM\ManyToMany(targetEntity: Sticker::class)]
    #[ORM\JoinTable(name: 'badge_board_sticker')]
    #[AuditField(represent: 'getText')]
    public Collection $badges;

    public function __construct()
    {
        $this->badges = new ArrayCollection();
    }
}
