<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The same shape as MisdeclaredInverseTracking, without trackElements — and that is
 * the whole point: element tracking was refused here, so the declaration reads as
 * supported, while membership has no road to travel either. Doctrine keeps a
 * ManyToMany on its owning side, and the elements of this one reach back through a
 * collection rather than a reference the unit of work reports one by one.
 */
#[ORM\Entity]
#[Auditable(type: 'untracked_inverse')]
class UntrackedInverseManyToMany
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name = 'x';

    /** @var Collection<int, TrackedGroupMember> */
    #[ORM\ManyToMany(targetEntity: TrackedGroupMember::class, mappedBy: 'groups')]
    #[AuditField(represent: 'getName')]
    public Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }
}
