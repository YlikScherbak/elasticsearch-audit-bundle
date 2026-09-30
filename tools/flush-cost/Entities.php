<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tools\FlushCost;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/*
 * The benchmark's own two entities, and nothing of the tests' fixtures: the same classes can then
 * be measured against every release of the bundle, whatever its fixtures were.
 */

#[ORM\Entity]
#[ORM\Table(name: 'bench_audited')]
#[Auditable(type: 'bench')]
class Audited
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column, AuditField] public string $label)
    {
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'bench_plain')]
class Plain
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $name)
    {
    }
}
