<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/** An audited entity whose one audited column may be nothing: a creation with nothing to say. */
#[ORM\Entity]
#[Auditable(type: 'beacon')]
class Beacon
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(nullable: true), AuditField]
    public ?string $label = null;
}
