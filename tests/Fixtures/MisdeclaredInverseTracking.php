<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The subtler mistake: the INVERSE side of a ManyToMany. It has a mappedBy, so it
 * looks servable — but its elements reach back through a collection, which the unit
 * of work never reports element-by-element to this side.
 */
#[ORM\Entity]
#[Auditable(type: 'misdeclared_inverse')]
class MisdeclaredInverseTracking
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name = 'x';

    /** @var Collection<int, TrackedGroupMember> */
    #[ORM\ManyToMany(targetEntity: TrackedGroupMember::class, mappedBy: 'groups')]
    #[AuditField(represent: 'getName', trackElements: true)]
    public Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }
}
