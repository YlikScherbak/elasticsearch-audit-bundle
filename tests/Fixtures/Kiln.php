<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * An Oven that records a field of its own table always, beside its root's: a change of one
 * table has a context in both.
 */
#[ORM\Entity]
#[Auditable(type: 'oven', alwaysRecord: ['site', 'firing'])]
class Kiln extends Oven
{
    #[ORM\Column, AuditField]
    public string $firing = 'bisque';

    #[ORM\Column, AuditField]
    public int $heat = 900;
}
