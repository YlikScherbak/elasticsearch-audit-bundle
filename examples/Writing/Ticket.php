<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Writing;

use Borsche\ElasticsearchAuditBundle\Contract\AuditableInterface;
use Borsche\ElasticsearchAuditBundle\Contract\TracksCollectionElementsInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The same auditing declared in code instead of attributes. Both describe the same
 * thing and the bundle treats them identically — use this one when the field list
 * depends on runtime state, or when a representer needs more than a method name.
 *
 * Attributes cannot hold closures, which is the usual reason to be here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'example_ticket')]
class Ticket implements AuditableInterface, TracksCollectionElementsInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $subject = '';

    #[ORM\Column]
    public string $state = 'open';

    #[ORM\Column]
    public bool $confidential = false;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    public ?Customer $reporter = null;

    /** @var Collection<int, TicketComment> */
    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: TicketComment::class, cascade: ['persist'])]
    public Collection $comments;

    public function __construct(string $subject)
    {
        $this->subject = $subject;
        $this->comments = new ArrayCollection();
    }

    public function getAuditObjectType(): string
    {
        return 'ticket';
    }

    /**
     * Scalars map to null; associations map to a callable turning the related object
     * into what the history should show.
     *
     * The subject of a confidential ticket is not recorded at all here — the list is
     * built per instance, which is the whole point of doing this in code. Redaction
     * would be the other way to arrive at that, and the difference is worth knowing:
     * a redacted field still says *that* it changed, an undeclared one says nothing.
     */
    public function getAuditedFields(): array
    {
        $fields = ['state' => null];

        if (!$this->confidential) {
            $fields['subject'] = null;
        }

        // A closure can do what a method name cannot: reach for a second value, or
        // build a small array. Keep it deterministic and free of side effects — it
        // runs while a flush is in progress.
        $fields['reporter'] = static fn (Customer $c): string => sprintf('%s (#%d)', $c->getName(), $c->id ?? 0);
        $fields['comments'] = static fn (TicketComment $c): string => $c->getExcerpt();

        return $fields;
    }

    /**
     * Written on every update even when they did not change, so a line of history
     * reads on its own: "the subject changed" is worth less than "the subject
     * changed, on a ticket that is open".
     *
     * @return list<string>
     */
    public function getAlwaysRecordedFields(): array
    {
        return ['state'];
    }

    /**
     * The runtime equivalent of `#[AuditField(trackElements: …)]`: true for every
     * field of the element that changed, or the list of fields to take.
     *
     * @return array<string, bool|list<string>>
     */
    public function getTrackedCollections(): array
    {
        return ['comments' => ['body']];
    }
}
