<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Identified by an object rather than by an int or a string — a Uuid, in every
 * application that has one, and a Sku here.
 *
 * Every other fixture is identified the two ways an audit record can carry unchanged:
 * an int, or a string. This one has to be turned into a string on the way in, and the
 * whole of the history it will ever have hangs on that being done.
 */
#[ORM\Entity]
#[Auditable(type: 'pouch')]
class Pouch
{
    #[ORM\Id, ORM\Column(type: SkuType::NAME)]
    public Sku $id;

    #[ORM\Column, AuditField]
    public string $contents;

    public function __construct(Sku $id, string $contents)
    {
        $this->id = $id;
        $this->contents = $contents;
    }
}
