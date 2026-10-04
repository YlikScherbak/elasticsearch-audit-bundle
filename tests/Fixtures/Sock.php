<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/** A line of a {@see Drawer}: one coloured "refuses" cannot be represented. */
#[ORM\Entity]
class Sock
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'socks')]
    public ?Drawer $drawer = null;

    public function __construct(#[ORM\Column] public string $colour)
    {
    }

    public function getColour(): string
    {
        return $this->colour === 'refuses' ? throw new \RuntimeException('This sock refuses to be represented.') : $this->colour;
    }
}
