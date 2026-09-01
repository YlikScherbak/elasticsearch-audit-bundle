<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TrackedGroupMember
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $name = 'member';

    /** @var Collection<int, MisdeclaredInverseTracking> */
    #[ORM\ManyToMany(targetEntity: MisdeclaredInverseTracking::class, inversedBy: 'members')]
    public Collection $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }
}
