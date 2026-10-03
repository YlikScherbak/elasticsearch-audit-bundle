<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class HeavyLoad extends Load
{
}
