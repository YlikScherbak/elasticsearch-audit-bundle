<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * A key made of two columns: what the connection sees as `WHERE orderRef = ? AND position = ?`,
 * which a statement is bound to only when both are there and nothing else is.
 */
#[ORM\Entity]
class CompositeLine
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 16)] public string $orderRef,
        #[ORM\Id, ORM\Column] public int $position,
        #[ORM\Column] public int $quantity = 1,
    ) {
    }
}
