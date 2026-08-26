<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Attribute;

/**
 * Marks an entity for automatic auditing. Fields are declared with #[AuditField].
 *
 *   #[ORM\Entity]
 *   #[Auditable(type: 'article', alwaysRecord: ['status'])]
 *   class Article { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Auditable
{
    /**
     * @param string       $type         stored as "objectType"
     * @param list<string> $alwaysRecord scalar fields recorded on every update even when unchanged
     */
    public function __construct(
        public readonly string $type,
        public readonly array $alwaysRecord = [],
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('#[Auditable] needs a non-empty type.');
        }
    }
}
