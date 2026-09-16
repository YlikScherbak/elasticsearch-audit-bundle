<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited collection whose lines carry their identifier from the start.
 *
 * Folder and Vault stage the other half of the same question: their documents get an
 * identity column, so a line has no id until the INSERT runs. A line here is named by
 * the application, which is what a sequence does too — Postgres under DBAL 3 maps a
 * generated column to one and hands the number out at persist() time. Both are
 * insertions, and the listener has to treat them as insertions whether or not the id
 * happens to be there already.
 */
#[ORM\Entity]
#[Auditable(type: 'ledger')]
class Ledger
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var Collection<int, LedgerLine> */
    #[ORM\OneToMany(mappedBy: 'ledger', targetEntity: LedgerLine::class, cascade: ['persist'])]
    #[AuditField(represent: 'label')]
    public Collection $lines;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->lines = new ArrayCollection();
    }

    public function add(LedgerLine $line): void
    {
        $line->ledger = $this;
        $this->lines->add($line);
    }
}
