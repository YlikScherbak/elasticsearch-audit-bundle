<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/** Whose card takes its key ({@see MemberCard}). */
#[ORM\Entity]
#[ORM\Table(name: 'club_member')]
class Member
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $name)
    {
    }
}
