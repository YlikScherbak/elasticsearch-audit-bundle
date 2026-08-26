<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * A composite identifier one half of which is an association — the shape a
 * join-table-with-payload entity has.
 */
#[ORM\Entity]
#[Auditable(type: 'reaction')]
class Reaction
{
    #[ORM\Id, ORM\ManyToOne]
    public Article $article;

    #[ORM\Id, ORM\Column(length: 16)]
    public string $kind;

    #[ORM\Column, AuditField]
    public int $count = 1;

    public function __construct(Article $article, string $kind)
    {
        $this->article = $article;
        $this->kind = $kind;
    }
}
