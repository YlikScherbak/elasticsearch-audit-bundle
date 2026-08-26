<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Contract\AuditableInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Declares its audit through the interface: scalars, a to-one, a to-many, and one
 * always-recorded field.
 */
#[ORM\Entity]
class Article implements AuditableInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $title;

    #[ORM\Column]
    public string $status = 'draft';

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    public int $views = 0; // not audited

    #[ORM\ManyToOne]
    public ?Author $author = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    public Collection $tags;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->tags = new ArrayCollection();
    }

    public function getAuditObjectType(): string
    {
        return 'article';
    }

    public function getAuditedFields(): array
    {
        return [
            'title' => null,
            'status' => null,
            'publishedAt' => null,
            'author' => static fn (Author $a): string => $a->name,
            'tags' => static fn (Tag $t): string => $t->label,
        ];
    }

    public function getAlwaysRecordedFields(): array
    {
        return ['status'];
    }
}
