<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits a column no INSERT writes: the row takes the database's default, the object keeps
 * what it was given.
 */
#[ORM\Entity]
#[Auditable('not_insertable_column_order')]
class NotInsertableColumnOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    #[AuditField]
    public string $reference;

    #[ORM\Column(insertable: false, options: ['default' => 'database'])]
    #[AuditField]
    public string $openedBy = 'system';

    public function __construct(string $reference)
    {
        $this->reference = $reference;
    }
}
