<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** An audited collection the database empties, whose representer fails for one of its {@see Sock}s. */
#[ORM\Entity]
#[Auditable(type: 'drawer')]
class Drawer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Sock> */
    #[ORM\OneToMany(mappedBy: 'drawer', targetEntity: Sock::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[AuditField(represent: 'getColour')]
    public Collection $socks;

    public function __construct()
    {
        $this->socks = new ArrayCollection();
    }

    public function add(Sock $sock): void
    {
        $sock->drawer = $this;
        $this->socks->add($sock);
    }
}
