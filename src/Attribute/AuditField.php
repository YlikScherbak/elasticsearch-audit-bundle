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
 *
 * A to-many field records which elements were added and removed. What changes
 * inside an element — a line's quantity — is a change to that element, not to the
 * collection, and Doctrine reports it as such, so it is recorded only when asked
 * for: trackElements: true takes every field of the element that changed, and a
 * list takes those fields only.
 *
 *   #[ORM\OneToMany(mappedBy: 'order')]
 *   #[AuditField(represent: 'getSku', trackElements: ['quantity'])]
 *   private Collection $lines;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AuditField
{
    /**
     * @param bool|list<string> $trackElements false, every changed field (true), or the fields to take
     */
    public function __construct(
        public readonly ?string $represent = null,
        public readonly bool|array $trackElements = false,
    ) {
        if (\is_array($trackElements) && $trackElements === []) {
            throw new \InvalidArgumentException('trackElements: [] tracks nothing; leave it out, or pass true for every changed field.');
        }
    }
}
