<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits a column no UPDATE ever writes: the property moves in PHP, the row does not.
 */
#[ORM\Entity]
#[Auditable('read_only_column_order')]
class ReadOnlyColumnOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    #[AuditField]
    public string $reference;

    #[ORM\Column(updatable: false)]
    #[AuditField]
    public string $openedBy = 'system';

    public function __construct(string $reference)
    {
        $this->reference = $reference;
    }
}
