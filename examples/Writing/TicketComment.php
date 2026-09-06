<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Writing;

use Doctrine\ORM\Mapping as ORM;

/**
 * An element of Ticket::$comments, watched through getTrackedCollections().
 */
#[ORM\Entity]
#[ORM\Table(name: 'example_ticket_comment')]
class TicketComment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'comments')]
    public ?Ticket $ticket = null;

    #[ORM\Column]
    public string $body;

    public function __construct(string $body)
    {
        $this->body = $body;
    }

    public function getExcerpt(): string
    {
        return mb_substr($this->body, 0, 40);
    }
}
