<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * The root of a JOINED hierarchy that records a field of its own table always: what the
 * context beside a change of a Kiln is made of, when the change is in the other table.
 */
#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['oven' => Oven::class, 'kiln' => Kiln::class])]
#[Auditable(type: 'oven', alwaysRecord: ['site'])]
class Oven
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $site = 'north';

    #[ORM\Column, AuditField]
    public string $label = 'one';
}
