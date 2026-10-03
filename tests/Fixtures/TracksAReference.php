<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * A declaration with a mistake: a to-one association asked to track its elements. It points
 * at one row, and there is no collection of them to watch.
 */
#[ORM\Entity]
#[Auditable(type: 'tracks_a_reference')]
class TracksAReference
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tag::class), AuditField(represent: 'getLabel', trackElements: true)]
    public ?Tag $tag = null;
}
