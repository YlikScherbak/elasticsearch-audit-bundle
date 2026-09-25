<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Columns whose value in the row is not the value on the object: a boolean the database may
 * hold as 0 or 1, an enum held as its string, a moment held to the second. A history read from
 * the row has to say what the object says for each -- and nothing, for a change that never
 * reached the row.
 */
#[ORM\Entity]
#[Auditable(type: 'switchboard')]
class Switchboard
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public bool $lit = false;

    #[ORM\Column(enumType: SwitchMode::class), AuditField]
    public SwitchMode $mode = SwitchMode::Manual;

    #[ORM\Column(type: 'datetime_immutable'), AuditField]
    public \DateTimeImmutable $checkedAt;

    public function __construct()
    {
        $this->checkedAt = new \DateTimeImmutable('2026-09-25 10:00:00');
    }
}
