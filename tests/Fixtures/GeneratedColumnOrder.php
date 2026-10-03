<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits a column the database generates on INSERT, and which Doctrine still writes: what the
 * object holds is what it held before the write. Generated alone -- neither left out of the
 * INSERT nor of the UPDATE -- so it is refused for being generated and nothing else.
 */
#[ORM\Entity]
#[Auditable('generated_column_order')]
class GeneratedColumnOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    #[AuditField]
    public string $reference;

    #[ORM\Column(generated: 'INSERT', options: ['default' => 'database'])]
    #[AuditField]
    public string $stamp = 'php';

    public function __construct(string $reference)
    {
        $this->reference = $reference;
    }
}
