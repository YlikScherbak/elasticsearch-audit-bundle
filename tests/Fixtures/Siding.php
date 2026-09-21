<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An owning collection whose representer throws, emptied on its own.
 *
 * Vault stages the same broken representer on the road through a lifecycle event: its
 * elements are inserted, so the representer runs in postFlush against a record that is
 * already in the list. This one stages the other road. Emptying an owning collection
 * gives its owner no event at all, so the record is built from nothing but this map —
 * and the representer fails while it is being built rather than while it is being
 * amended.
 *
 * Both roads report through the failure policy, and under `throw` that used to end the
 * assembling of every other record in the flush.
 */
#[ORM\Entity]
#[Auditable(type: 'siding')]
class Siding
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var Collection<int, FolderDocument> */
    #[ORM\ManyToMany(targetEntity: FolderDocument::class)]
    #[AuditField(represent: 'explode')]
    public Collection $planks;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->planks = new ArrayCollection();
    }
}
