<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * KeepsABlobUnaudited's twin with a BINARY column: DBAL converts the two types by different
 * code, and on PostgreSQL both are bytea read back as a stream.
 */
#[ORM\Entity]
#[Auditable(type: 'digest')]
class KeepsABinaryUnaudited
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var resource|string|null */
    #[ORM\Column(type: Types::BINARY, length: 255, nullable: true)]
    public mixed $content = null;

    public function __construct(string $name, mixed $content = null)
    {
        $this->name = $name;
        $this->content = $content;
    }
}
