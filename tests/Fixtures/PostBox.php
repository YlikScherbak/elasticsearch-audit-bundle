<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits a property Doctrine does not map at all.
 *
 * The plainest version of a declaration that cannot be honoured: the attribute is on a
 * property, the property is on the class, and nothing about it will ever reach the
 * database — so nothing about it can ever be recorded. Customer stages the subtler
 * cousin, where the property is mapped but under other names.
 */
#[ORM\Entity]
#[Auditable(type: 'post_box')]
class PostBox
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $label = 'x';

    /** No ORM attribute: Doctrine maps neither a field nor an association for it. */
    #[AuditField]
    public string $nickname = 'y';
}
