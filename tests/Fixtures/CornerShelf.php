<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/** The subclass of {@see Shelf}, inheriting its labels. */
#[ORM\Entity]
class CornerShelf extends Shelf
{
    #[ORM\Column]
    public int $angle = 90;
}
