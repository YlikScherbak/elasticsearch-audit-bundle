<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A crate whose lines are shown with a column that may hold nothing.
 *
 * It exists for one question: whether what is shown of a deleted line can tell a stored
 * NULL from a value the line was about to be given. A representer that never shows a
 * nullable column cannot tell, so every other fixture here passes whichever way that goes.
 */
#[ORM\Entity]
#[Auditable(type: 'bin')]
class Bin
{
    #[ORM\Id, ORM\Column(length: 16)]
    public string $code;

    /** @var Collection<int, BinItem> */
    #[ORM\OneToMany(mappedBy: 'bin', targetEntity: BinItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[AuditField(represent: 'getLabel')]
    public Collection $items;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->items = new ArrayCollection();
    }

    public function add(BinItem $item): void
    {
        $item->bin = $this;
        $this->items->add($item);
    }
}
