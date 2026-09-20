<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * An element with an association of its own, declared before its columns.
 *
 * Doctrine reports a change set in the order the class declares its fields, so the
 * inspector is what the reading of this element reaches first — and an association is
 * not something that changed *inside* the element, so it is passed over. Everything
 * behind it is what says whether passing over means carrying on.
 */
#[ORM\Entity]
class Chute
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    public ?Hopper $hopper = null;

    /** Declared before the columns, and nothing to do with the collection this belongs to. */
    #[ORM\ManyToOne]
    public ?Author $inspector = null;

    #[ORM\Column]
    public int $size = 0;

    public function __construct(int $size = 0)
    {
        $this->size = $size;
    }

    public function getSize(): string
    {
        return (string) $this->size;
    }
}
