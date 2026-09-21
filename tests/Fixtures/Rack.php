<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited collection Doctrine keys by one of the element's own columns.
 *
 * Every other collection fixture is a list, and a list is what comes back out of it
 * whether or not anything re-lists it on the way. This one comes back keyed by strings,
 * which is the only shape that can say whether a record's "old" and "new" sides are
 * arrays or objects — and a history where a collection is sometimes one and sometimes
 * the other is a mapping that cannot be written.
 *
 * Owning side on purpose: an inverse collection is not what Doctrine persists, so
 * emptying one schedules nothing and there is no snapshot to read back.
 */
#[ORM\Entity]
#[Auditable(type: 'rack')]
class Rack
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var Collection<string, RackSlot> */
    #[ORM\ManyToMany(targetEntity: RackSlot::class, indexBy: 'code')]
    #[AuditField(represent: 'getCode')]
    public Collection $slots;

    /**
     * The other kind of news an owner can have, on the same owner: what changed inside a
     * line of it. A record built after the flush can carry both at once, and the two
     * arrive by different roads.
     *
     * @var Collection<int, RackNote>
     */
    #[ORM\OneToMany(mappedBy: 'rack', targetEntity: RackNote::class, cascade: ['persist'])]
    #[AuditField(represent: 'getText', trackElements: ['text'])]
    public Collection $notes;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->slots = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }

    public function note(RackNote $note): void
    {
        $note->rack = $this;
        $this->notes->add($note);
    }

    public function add(RackSlot $slot): void
    {
        $this->slots->set($slot->code, $slot);
    }
}
