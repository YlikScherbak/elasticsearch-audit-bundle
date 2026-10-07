<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits a BINARY column, which DBAL reads back as a stream just as it does a BLOB.
 */
#[ORM\Entity]
#[Auditable(type: 'binary_document')]
class AuditsABinary
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var resource|string|null */
    #[ORM\Column(type: Types::BINARY, length: 64, nullable: true), AuditField]
    public mixed $digest = null;

    public function __construct(string $name, mixed $digest = null)
    {
        $this->name = $name;
        $this->digest = $digest;
    }
}
