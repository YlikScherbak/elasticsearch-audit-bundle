<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Writing;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited entity, declared with attributes. Nothing else is needed: the listener
 * reads Doctrine's change set on every flush and writes one record per entity.
 *
 * Four declarations worth knowing, all of them on this class:
 *
 * - `type` is what the history is filtered by, and it is yours to choose. Keep it
 *   stable — it is written into every document, and renaming it later splits the
 *   history of one thing into two.
 * - `alwaysRecord` names scalar fields written on every update even when unchanged.
 *   The status here is context: without it, "total went from 90 to 120" is a line
 *   nobody can read on its own.
 * - a scalar field needs only `#[AuditField]`.
 * - an association needs a **representer** — a method on the related object whose
 *   result is stored. A history cannot hold an entity, and "something changed" is
 *   not an answer, so the declaration is refused without one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'example_order')]
#[Auditable(type: 'order', alwaysRecord: ['status'])]
class Order
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $status = 'draft';

    #[ORM\Column, AuditField]
    public int $totalCents = 0;

    /**
     * A note the application keeps but nobody wants in the trail: leave it
     * undeclared and it is never recorded. Auditing is a list of what to keep, not
     * of what to skip.
     */
    #[ORM\Column(nullable: true)]
    public ?string $internalNote = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[AuditField(represent: 'getName')]
    public ?Customer $customer = null;

    /**
     * A to-many side records **membership** — which lines came and went, each one
     * as its representer sees it. What changes *inside* a line is a change to that
     * line, and Doctrine reports it as such, so it is recorded only when asked for:
     * `trackElements: ['quantity']` takes that field, `true` takes every field of
     * the element that changed.
     *
     * Element changes are recorded **on the owner** — this order — which is also
     * what a redaction rule has to be scoped to.
     *
     * @var Collection<int, OrderLine>
     */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderLine::class, cascade: ['persist'])]
    #[AuditField(represent: 'getSku', trackElements: ['quantity'])]
    public Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function add(OrderLine $line): void
    {
        $line->order = $this;
        $this->lines->add($line);
    }
}
