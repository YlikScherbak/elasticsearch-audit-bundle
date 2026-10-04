<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/** What a {@see Poster} is labelled with; held by no other collection. */
#[ORM\Entity]
class Sticker
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $text)
    {
    }

    public function getText(): string
    {
        return $this->text;
    }
}
