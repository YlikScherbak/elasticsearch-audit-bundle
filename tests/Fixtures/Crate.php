<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Like Shipment, but with an identifier the application assigns — which Doctrine does
 * not clear when the row is deleted, so a removed owner still has a name.
 */
#[ORM\Entity]
#[Auditable(type: 'crate', alwaysRecord: ['status'])]
class Crate
{
    #[ORM\Id, ORM\Column(length: 16)]
    public string $code;

    #[ORM\Column, AuditField]
    public string $status = 'packed';

    #[ORM\Column]
    public string $internalNote = ''; // not audited

    /** @var Collection<int, CrateItem> */
    #[ORM\OneToMany(mappedBy: 'crate', targetEntity: CrateItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[AuditField(represent: 'getSku', trackElements: true)]
    public Collection $items;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->items = new ArrayCollection();
    }

    public function add(CrateItem $item): void
    {
        $item->crate = $this;
        $this->items->add($item);
    }
}
