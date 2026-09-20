<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * An element with two owners, which is the only shape that can tell whether the walk
 * over an element's associations carries on past the first one it has news about.
 *
 * Every other element fixture belongs to one collection, so a walk that stopped at the
 * first association it handled would look exactly like one that carried on. A case sits
 * on a pallet and in a depot at the same time, and both of them audit it.
 */
#[ORM\Entity]
class PackingCase
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Declared first, so it is the association the walk reaches first. */
    #[ORM\ManyToOne(inversedBy: 'cases')]
    public ?Pallet $pallet = null;

    #[ORM\ManyToOne(inversedBy: 'cases')]
    public ?Depot $depot = null;

    /** A third owner, used only by the declaration tests: never set in the others. */
    #[ORM\ManyToOne(inversedBy: 'cases')]
    public ?Yard $yard = null;

    #[ORM\Column]
    public string $label;

    #[ORM\Column]
    public int $weight = 0;

    public function __construct(string $label, int $weight = 0)
    {
        $this->label = $label;
        $this->weight = $weight;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
