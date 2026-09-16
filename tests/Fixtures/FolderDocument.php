<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * Not audited in its own right: it is what a folder gained or lost.
 */
#[ORM\Entity]
class FolderDocument
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    public ?Folder $folder = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    public ?Vault $vault = null;

    #[ORM\Column]
    public string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * A representer that fails, for staging application code that throws in postFlush:
     * the representer of an element being inserted runs there, once the row is written
     * and its id is final, so what it costs is the history of that line and not the
     * flush the application asked for.
     */
    public function explode(): string
    {
        throw new \RuntimeException('this representer cannot read the document');
    }
}
