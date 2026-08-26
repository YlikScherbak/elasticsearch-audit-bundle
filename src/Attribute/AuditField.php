<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Attribute;

/**
 * Marks a property of an #[Auditable] entity as recorded.
 *
 * Scalars need nothing more. For an association, name a method — on the related
 * object — whose result is stored instead of the object itself:
 *
 *   #[ORM\ManyToOne] #[AuditField(represent: 'getName')]  private User $author;
 *   #[ORM\ManyToMany] #[AuditField(represent: 'getLabel')] private Collection $tags;
 *
 * Attributes cannot hold closures; when a method name is not expressive enough,
 * implement AuditableInterface instead.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AuditField
{
    public function __construct(public readonly ?string $represent = null)
    {
    }
}
