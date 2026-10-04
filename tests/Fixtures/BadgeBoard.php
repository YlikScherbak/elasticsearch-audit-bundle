<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/** The entity of a mapped superclass that declares itself audited too. */
#[ORM\Entity]
#[Auditable(type: 'badge_board')]
class BadgeBoard extends Badged
{
}
