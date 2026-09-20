<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Identified by a float, which is a thing Doctrine will map and nothing should do: two
 * rows a hair apart are one document id as soon as anything rounds, and an audit trail
 * cannot be read back from that.
 *
 * Here so the refusal has something to refuse. The listener turns an identifier into
 * the string a document is stored under, and the types it can do that with are named
 * one by one — a value that is none of them is a mistake in a declaration, and saying
 * so is the difference between a history that is missing and a history nobody knows is
 * missing.
 */
#[ORM\Entity]
#[Auditable(type: 'gauge')]
class Gauge
{
    #[ORM\Id, ORM\Column(type: 'float')]
    public float $id;

    #[ORM\Column, AuditField]
    public string $reading;

    public function __construct(float $id, string $reading)
    {
        $this->id = $id;
        $this->reading = $reading;
    }
}
