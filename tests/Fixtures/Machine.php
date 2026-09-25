<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited root of a JOINED hierarchy: its own columns in its own table, a subclass's in
 * another. One change of an entity is then one statement per table it touched -- or one, when
 * it touched only one -- which is what a record has to be tied to as a whole.
 */
#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['machine' => Machine::class, 'press' => Press::class])]
#[Auditable(type: 'machine')]
class Machine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
