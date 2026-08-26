<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Declares its audit through attributes, with a string identifier.
 */
#[ORM\Entity]
#[Auditable(type: 'comment', alwaysRecord: ['approved'])]
class Comment
{
    #[ORM\Id, ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column, AuditField]
    public string $body;

    #[ORM\Column, AuditField]
    public bool $approved = false;

    #[ORM\Column]
    public int $likes = 0; // not audited

    #[ORM\ManyToOne, AuditField(represent: 'getName')]
    public ?Author $author = null;

    public function __construct(string $id, string $body)
    {
        $this->id = $id;
        $this->body = $body;
    }
}
