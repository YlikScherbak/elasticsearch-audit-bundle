<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Identified by an enum — a small fixed set of rows, named rather than numbered, which
 * is how a warehouse writes down its bays.
 */
#[ORM\Entity]
#[Auditable(type: 'berth')]
class Berth
{
    #[ORM\Id, ORM\Column(type: 'integer', enumType: BayNumber::class)]
    public BayNumber $id;

    #[ORM\Column, AuditField]
    public string $occupant;

    public function __construct(BayNumber $id, string $occupant)
    {
        $this->id = $id;
        $this->occupant = $occupant;
    }
}
