<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/** Keyed by text that is sometimes digits: what a {@see TicketBook} holds; held by no other collection. */
#[ORM\Entity]
class Ticket
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 8)]
        public string $code,
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }
}
