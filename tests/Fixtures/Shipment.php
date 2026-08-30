<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An owner whose to-many side is worth auditing element by element: what changes in
 * a shipment is usually a line's quantity, not which lines it has.
 */
#[ORM\Entity]
#[Auditable(type: 'shipment')]
class Shipment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $reference;

    /** @var array<string, mixed> a json column, the shape "permissions" usually has */
    #[ORM\Column(type: 'json'), AuditField]
    public array $meta = [];

    /** @var Collection<int, ShipmentLine> */
    #[ORM\OneToMany(mappedBy: 'shipment', targetEntity: ShipmentLine::class, cascade: ['persist'])]
    #[AuditField(represent: 'getLabel', trackElements: ['quantity'])]
    public Collection $lines;

    public function __construct(string $reference)
    {
        $this->reference = $reference;
        $this->lines = new ArrayCollection();
    }

    public function add(ShipmentLine $line): void
    {
        $line->shipment = $this;
        $this->lines->add($line);
    }
}
