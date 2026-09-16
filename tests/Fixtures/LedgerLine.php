<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * A line of a ledger, identified by the application rather than by the database.
 */
#[ORM\Entity]
class LedgerLine
{
    #[ORM\Id, ORM\Column]
    public string $id;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    public ?Ledger $ledger = null;

    #[ORM\Column]
    public string $caption;

    /**
     * Not mapped: a switch for the test that needs the representer to fail, so the
     * happy path and the failing one can share one fixture.
     */
    public bool $unreadable = false;

    public function __construct(string $id, string $caption)
    {
        $this->id = $id;
        $this->caption = $caption;
    }

    /**
     * Application code, run by the listener to name this line in the owner's record.
     */
    public function label(): string
    {
        if ($this->unreadable) {
            throw new \RuntimeException('this representer cannot read the line');
        }

        return $this->caption;
    }
}
