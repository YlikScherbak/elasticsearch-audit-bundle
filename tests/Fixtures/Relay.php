<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Relays hand on to each other, and two created pointing at each other are a cycle no order of
 * INSERTs satisfies: Doctrine inserts one with its reference empty and writes it afterwards, in
 * an UPDATE nobody announces (UnitOfWork::scheduleExtraUpdate()).
 */
#[ORM\Entity]
#[Auditable(type: 'relay')]
class Relay
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[AuditField(represent: 'getName')]
    public ?Relay $next = null;

    public function __construct(
        #[ORM\Column, AuditField]
        public string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
