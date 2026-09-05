<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * A declaration that reads as supported and is not: alwaysRecord stores a field as it
 * is, beside the changes, which a related object cannot be.
 */
#[ORM\Entity]
#[Auditable(type: 'always_records_an_association', alwaysRecord: ['author'])]
class AlwaysRecordsAnAssociation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $title = 'x';

    #[ORM\ManyToOne]
    #[AuditField(represent: 'getName')]
    public ?Author $author = null;
}
