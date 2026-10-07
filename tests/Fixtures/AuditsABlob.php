<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits a BLOB column: a declaration that cannot be honoured, because the value is bytes - a
 * stream once Doctrine reads it back - which no history can hold and json_encode cannot write.
 */
#[ORM\Entity]
#[Auditable(type: 'blob_document')]
class AuditsABlob
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var resource|string|null */
    #[ORM\Column(type: Types::BLOB, nullable: true), AuditField]
    public mixed $content = null;

    public function __construct(string $name, mixed $content = null)
    {
        $this->name = $name;
        $this->content = $content;
    }
}
