<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * A tracked element of the one owner that also has a collection worth emptying, so that
 * a record built after the flush can have both kinds of news in it at once.
 */
#[ORM\Entity]
class RackNote
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    public ?Rack $rack = null;

    #[ORM\Column]
    public string $text;

    public function __construct(string $text)
    {
        $this->text = $text;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
