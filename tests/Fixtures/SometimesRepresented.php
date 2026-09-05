<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Contract\AuditableInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * An interface declaration that differs between two instances of the same class.
 *
 * That is what the interface form is for — the docblock on AuditableInterface says the
 * field list may depend on the instance — and it is also what made a validation cache
 * keyed on the class and the field names wrong: the names are identical here, and one
 * of these two instances declares an audited association with no representer, which is
 * a record saying "something changed" and not what.
 */
#[ORM\Entity]
class SometimesRepresented implements AuditableInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $title;

    #[ORM\ManyToOne]
    public ?Author $author = null;

    public function __construct(string $title, private readonly bool $represented = true)
    {
        $this->title = $title;
    }

    public function getAuditObjectType(): string
    {
        return 'sometimes_represented';
    }

    public function getAuditedFields(): array
    {
        return [
            'title' => null,
            'author' => $this->represented ? static fn (Author $a): string => $a->name : null,
        ];
    }

    public function getAlwaysRecordedFields(): array
    {
        return [];
    }
}
