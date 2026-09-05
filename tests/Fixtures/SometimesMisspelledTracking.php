<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Contract\AuditableInterface;
use Borsche\ElasticsearchAuditBundle\Contract\TracksCollectionElementsInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The other half of the same trap: two instances tracking the same collection and
 * naming different fields inside its elements. The collection names — which were the
 * whole cache key — are identical, and one of these two watches a field that does not
 * exist, which watches nothing at all for as long as the process runs.
 */
#[ORM\Entity]
class SometimesMisspelledTracking implements AuditableInterface, TracksCollectionElementsInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $reference;

    /** @var Collection<int, SometimesTrackedLine> */
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: SometimesTrackedLine::class, cascade: ['persist'])]
    public Collection $lines;

    public function __construct(string $reference, private readonly bool $spelled = true)
    {
        $this->reference = $reference;
        $this->lines = new ArrayCollection();
    }

    public function getAuditObjectType(): string
    {
        return 'sometimes_misspelled';
    }

    public function getAuditedFields(): array
    {
        return ['reference' => null, 'lines' => static fn (SometimesTrackedLine $l): int => $l->quantity];
    }

    public function getAlwaysRecordedFields(): array
    {
        return [];
    }

    public function getTrackedCollections(): array
    {
        return ['lines' => [$this->spelled ? 'quantity' : 'quanitity']];
    }
}
