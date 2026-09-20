<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited many-to-many on its owning side, which nothing else in this suite is.
 *
 * The difference matters to the record. An inverse collection is not the side Doctrine
 * tracks, so what goes in and out of it is found through each element's own reference
 * back and recorded element by element. An owning one is dirty in its own right, and
 * the record carries it whole: the list it held and the list it holds. Two different
 * roads to "which stops are on this route", and only one of them had a fixture.
 */
#[ORM\Entity]
#[Auditable(type: 'route')]
class Route
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $code;

    /** @var Collection<int, Stop> */
    #[ORM\ManyToMany(targetEntity: Stop::class)]
    #[AuditField(represent: 'getName')]
    public Collection $stops;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->stops = new ArrayCollection();
    }
}
