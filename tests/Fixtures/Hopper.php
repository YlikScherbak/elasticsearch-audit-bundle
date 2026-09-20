<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An owner that tracks its elements without naming the fields to track.
 *
 * Crate is the other fixture shaped like this, and its elements have nothing but
 * scalars behind the association they belong through. A chute has an association of
 * its own, declared before its columns — which is what decides whether "an association
 * of an element is not a change inside it" is a skip or a full stop.
 */
#[ORM\Entity]
#[Auditable(type: 'hopper')]
class Hopper
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var Collection<int, Chute> */
    #[ORM\OneToMany(mappedBy: 'hopper', targetEntity: Chute::class, cascade: ['persist'])]
    #[AuditField(represent: 'getSize', trackElements: true)]
    public Collection $chutes;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->chutes = new ArrayCollection();
    }

    public function add(Chute $chute): void
    {
        $chute->hopper = $this;
        $this->chutes->add($chute);
    }
}
