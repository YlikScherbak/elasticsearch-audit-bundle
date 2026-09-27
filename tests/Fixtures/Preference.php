<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited column whose object value is not a value its database form can be read back from
 * as it is: an array, held by the row as JSON. What the listener remembers of the row has to be
 * the row's form, or it cannot be read back at all.
 */
#[ORM\Entity]
#[Auditable(type: 'preference')]
class Preference
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json'), AuditField]
    public array $options = [];
}
