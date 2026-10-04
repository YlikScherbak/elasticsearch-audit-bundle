<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** An audited link to targets keyed by text, some of it digits. */
#[ORM\Entity]
#[Auditable(type: 'ticket_book')]
class TicketBook
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Ticket> */
    #[ORM\ManyToMany(targetEntity: Ticket::class)]
    #[ORM\JoinTable(name: 'ticket_book_ticket')]
    #[ORM\InverseJoinColumn(name: 'ticket_code', referencedColumnName: 'code')]
    #[AuditField(represent: 'getCode')]
    public Collection $tickets;

    public function __construct()
    {
        $this->tickets = new ArrayCollection();
    }
}
