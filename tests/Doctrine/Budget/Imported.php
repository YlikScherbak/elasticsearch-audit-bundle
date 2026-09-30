<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Budget;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * The budget's audited entity ({@see \Borsche\ElasticsearchAuditBundle\Tests\Doctrine\HowMuchTheListenerKeepsTest}),
 * mapped apart from the fixtures: what the listener keeps depends on every class the manager
 * knows, and a fixture a new test points an audited association at would move the budget.
 */
#[ORM\Entity]
#[ORM\Table(name: 'budget_imported')]
#[Auditable(type: 'imported')]
class Imported
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column, AuditField] public string $label)
    {
    }
}
