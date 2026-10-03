<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tracking declaration that is servable in every way except the one that decides
 * what is recorded: an element field is misspelled, so the list matches nothing of it.
 * Named second, after one spelled right: a name that checks out is not the end of the list.
 */
#[ORM\Entity]
#[Auditable(type: 'misspelled_tracking')]
class MisspelledTracking
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name = 'x';

    /** @var Collection<int, ShipmentLine> */
    #[ORM\OneToMany(mappedBy: 'shipment', targetEntity: ShipmentLine::class)]
    #[AuditField(represent: 'getSku', trackElements: ['quantity', 'quanitity'])]
    public Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }
}
