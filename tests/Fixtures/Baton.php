<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/** What a {@see RelayTeam} hands on; held by no other collection. */
#[ORM\Entity]
class Baton
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $colour)
    {
    }

    public function getColour(): string
    {
        return $this->colour;
    }
}
