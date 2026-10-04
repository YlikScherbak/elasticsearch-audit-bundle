<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A mapped superclass declaring an audited link: its entities ({@see Poster}) inherit the mapping
 * and hold the join rows; it has no rows of its own.
 */
#[ORM\MappedSuperclass]
abstract class Labelled
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, Sticker> */
    #[ORM\ManyToMany(targetEntity: Sticker::class)]
    #[ORM\JoinTable(name: 'poster_sticker')]
    #[AuditField(represent: 'getText')]
    public Collection $stickers;

    public function __construct()
    {
        $this->stickers = new ArrayCollection();
    }
}
