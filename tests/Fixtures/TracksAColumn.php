<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * A declaration with a mistake: a plain column asked to track its elements. It has none.
 */
#[ORM\Entity]
#[Auditable(type: 'tracks_a_column')]
class TracksAColumn
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField(trackElements: true)]
    public string $note = 'x';
}
