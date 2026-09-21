<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The second owner, and the one with something to lose: it tracks what happens inside
 * a case, so a walk that stopped at the pallet would leave this collection silent about
 * a weight nobody can explain afterwards.
 *
 * Two tracked fields rather than one, and for the same reason it has two owners: reading
 * every tracked field and stopping at the first one with nothing to say look the same
 * when there is only one to read.
 */
#[ORM\Entity]
#[Auditable(type: 'depot')]
class Depot
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /**
     * Mapped and not audited. A flush can then give this depot an UPDATE of its own
     * while leaving its record with nothing to say, which is the only arrangement in
     * which the listener has to decide whether news from inside an element is reason
     * enough to keep that record where it stands.
     */
    #[ORM\Column]
    public string $note = '';

    /** @var Collection<int, PackingCase> */
    #[ORM\OneToMany(mappedBy: 'depot', targetEntity: PackingCase::class, cascade: ['persist'])]
    #[AuditField(represent: 'getLabel', trackElements: ['weight', 'label'])]
    public Collection $cases;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->cases = new ArrayCollection();
    }

    public function add(PackingCase $case): void
    {
        $case->depot = $this;
        $this->cases->add($case);
    }
}
