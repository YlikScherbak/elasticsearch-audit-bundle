<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Two audited collections, and a mistake in the second one.
 *
 * The check that reads element-tracking declarations skips a collection that names no
 * fields to track — there is nothing in it to be wrong — and carries on to the next.
 * Stopping there instead would leave every declaration behind the first untracked
 * collection unread, which is a mistake found on the day it costs history rather than
 * on the day it is written.
 *
 * The mistake itself is the other half: "pallet" is an association of a case and not one
 * of its columns, and element tracking records what changed inside an element, which
 * only its own scalar columns are reported as.
 */
#[ORM\Entity]
#[Auditable(type: 'yard')]
class Yard
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name = 'x';

    /** @var Collection<int, YardLabel> tracked without naming a field: nothing to check */
    #[ORM\OneToMany(mappedBy: 'yard', targetEntity: YardLabel::class)]
    #[AuditField(represent: 'getText', trackElements: true)]
    public Collection $labels;

    /** @var Collection<int, PackingCase> and the one with the mistake in it */
    #[ORM\OneToMany(mappedBy: 'yard', targetEntity: PackingCase::class)]
    #[AuditField(represent: 'getLabel', trackElements: ['pallet'])]
    public Collection $cases;

    public function __construct()
    {
        $this->labels = new ArrayCollection();
        $this->cases = new ArrayCollection();
    }
}
