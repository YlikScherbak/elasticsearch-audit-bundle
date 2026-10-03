<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited collection declared before an audited column: what the row says is read past the
 * collection, which the row does not hold.
 */
#[ORM\Entity]
#[Auditable(type: 'list_first')]
class ListFirst
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /**
     * Of relays, a class no other collection holds: the tests that count which collections
     * hold a tag, a stop or an author count exactly the ones they name.
     *
     * @var Collection<int, Relay>
     */
    #[ORM\ManyToMany(targetEntity: Relay::class)]
    #[ORM\JoinTable(name: 'list_first_relay')]
    #[AuditField(represent: 'getName')]
    public Collection $relays;

    #[ORM\Column, AuditField]
    public string $name = 'first';

    public function __construct()
    {
        $this->relays = new ArrayCollection();
    }
}
