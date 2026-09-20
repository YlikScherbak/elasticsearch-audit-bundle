<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits an embedded property, which Doctrine never reports under that name.
 */
#[ORM\Entity]
#[Auditable('customer')]
class Customer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    #[AuditField]
    public string $name;

    #[ORM\Embedded(class: Address::class)]
    #[AuditField]
    public Address $address;

    /**
     * A column whose name starts the way the embeddable's does and has nothing to do
     * with it. The refusal above lists the embeddable's own columns, and "starts with
     * address" would sweep this one in beside them — naming a column the developer is
     * then told to audit instead of the one they meant.
     */
    #[ORM\Column]
    public string $addressNote = '';

    public function __construct(string $name, Address $address)
    {
        $this->name = $name;
        $this->address = $address;
    }
}
