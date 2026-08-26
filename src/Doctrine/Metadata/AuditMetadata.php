<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Metadata;

/**
 * What to record for one entity class — the common form both AuditableInterface
 * and the attributes are reduced to.
 */
final class AuditMetadata
{
    /**
     * @param array<string, (callable(object): mixed)|null> $fields         property => representer for associations, null for scalars
     * @param list<string>                                  $alwaysRecorded
     */
    public function __construct(
        public readonly string $objectType,
        public readonly array $fields,
        public readonly array $alwaysRecorded = [],
    ) {
        foreach ($alwaysRecorded as $field) {
            if (!\array_key_exists($field, $fields)) {
                throw new \InvalidArgumentException(sprintf('"%s" is listed as always recorded but is not an audited field.', $field));
            }
        }
    }

    public function isAlwaysRecorded(string $field): bool
    {
        return \in_array($field, $this->alwaysRecorded, true);
    }
}
