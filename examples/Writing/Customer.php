<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Writing;

use Doctrine\ORM\Mapping as ORM;

/**
 * A related object that is not audited itself. It appears in the history through
 * the representer on Order::$customer — as a name, not as an entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'example_customer')]
class Customer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
