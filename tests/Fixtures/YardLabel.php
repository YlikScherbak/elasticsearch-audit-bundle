<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * An element of a collection that is tracked without naming any fields, which is the
 * shape the declaration check has nothing to say about and moves past.
 */
#[ORM\Entity]
class YardLabel
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'labels')]
    public ?Yard $yard = null;

    #[ORM\Column]
    public string $text = '';

    public function getText(): string
    {
        return $this->text;
    }
}
