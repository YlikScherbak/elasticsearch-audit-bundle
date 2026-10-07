<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited entity with a BLOB nobody audits: the bytes go through the statements the
 * listener watches, and must reach the database as they would without it.
 */
#[ORM\Entity]
#[Auditable(type: 'attachment')]
class KeepsABlobUnaudited
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var resource|string|null */
    #[ORM\Column(type: Types::BLOB, nullable: true)]
    public mixed $content = null;

    public function __construct(string $name, mixed $content = null)
    {
        $this->name = $name;
        $this->content = $content;
    }
}
