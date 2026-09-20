<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The owner a case is reached through first, and the one whose news makes the walk
 * decide it has something to say.
 */
#[ORM\Entity]
#[Auditable(type: 'pallet')]
class Pallet
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $code;

    /** @var Collection<int, PackingCase> */
    #[ORM\OneToMany(mappedBy: 'pallet', targetEntity: PackingCase::class, cascade: ['persist'])]
    #[AuditField(represent: 'getLabel')]
    public Collection $cases;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->cases = new ArrayCollection();
    }

    public function add(PackingCase $case): void
    {
        $case->pallet = $this;
        $this->cases->add($case);
    }
}
