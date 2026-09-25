<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/** The subclass of {@see Machine}, with a column of its own in a table of its own. */
#[ORM\Entity]
class Press extends Machine
{
    #[ORM\Column, AuditField]
    public int $tonnage = 1;
}
