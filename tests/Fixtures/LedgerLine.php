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
     * The caption that makes the representer fail, for the test that needs it to, so the
     * happy path and the failing one can share one fixture. A caption and not a switch of
     * its own: the representer is handed a copy made from the row, which holds what is
     * mapped and nothing else.
     */
    public const UNREADABLE = 'unreadable';

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
        if ($this->caption === self::UNREADABLE) {
            throw new \RuntimeException('this representer cannot read the line');
        }

        return $this->caption;
    }
}
