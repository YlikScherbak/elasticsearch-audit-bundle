<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Budget;

use Doctrine\ORM\Mapping as ORM;

/** The budget's entity nobody audits and no audited association points at: no history's rows. */
#[ORM\Entity]
#[ORM\Table(name: 'budget_unaudited')]
class Unaudited
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $name)
    {
    }
}
