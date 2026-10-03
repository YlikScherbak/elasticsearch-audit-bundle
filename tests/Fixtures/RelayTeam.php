<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A link whose join columns are declared and do not cascade: Doctrine's default is ON DELETE
 * CASCADE only where the mapping names no join columns. A team going takes its join rows with a
 * DELETE of Doctrine's own, before its row's.
 */
#[ORM\Entity]
#[Auditable(type: 'relay_team')]
class RelayTeam
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Baton> */
    #[ORM\ManyToMany(targetEntity: Baton::class)]
    #[ORM\JoinTable(name: 'relay_team_baton')]
    #[ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'baton_id', referencedColumnName: 'id')]
    #[AuditField(represent: 'getColour')]
    public Collection $batons;

    #[ORM\Column, AuditField]
    public string $name = 'team';

    public function __construct()
    {
        $this->batons = new ArrayCollection();
    }
}
