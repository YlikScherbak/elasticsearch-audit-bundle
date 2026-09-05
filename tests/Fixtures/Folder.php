<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An audited inverse collection with no element tracking: what goes in and out of it
 * is history, what happens inside a document of it is not. The common shape, and the
 * one where membership used to be recorded nowhere — Doctrine keeps the change on the
 * document's own reference back, so this collection never goes dirty.
 */
#[ORM\Entity]
#[Auditable(type: 'folder')]
class Folder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $name;

    /** @var Collection<int, FolderDocument> */
    #[ORM\OneToMany(mappedBy: 'folder', targetEntity: FolderDocument::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[AuditField(represent: 'getTitle')]
    public Collection $documents;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->documents = new ArrayCollection();
    }

    public function add(FolderDocument $document): void
    {
        $document->folder = $this;
        $this->documents->add($document);
    }
}
