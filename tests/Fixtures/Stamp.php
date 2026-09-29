<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited entity with nothing but a generated identifier: Doctrine plans its creation with
 * an empty change set wherever the database hands the key out on INSERT.
 */
#[ORM\Entity]
#[Auditable(type: 'stamp')]
class Stamp
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
}
