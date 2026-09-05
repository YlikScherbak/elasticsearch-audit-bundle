<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits a collection through a representer that throws. What it stages is application
 * code failing in postFlush: the representer of a newly inserted element runs there and
 * nowhere else, because the id it reads exists only after the commit.
 */
#[ORM\Entity]
#[Auditable(type: 'vault')]
class Vault
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var Collection<int, FolderDocument> */
    #[ORM\OneToMany(mappedBy: 'vault', targetEntity: FolderDocument::class, cascade: ['persist'])]
    #[AuditField(represent: 'explode')]
    public Collection $documents;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->documents = new ArrayCollection();
    }

    public function add(FolderDocument $document): void
    {
        $document->vault = $this;
        $this->documents->add($document);
    }
}
