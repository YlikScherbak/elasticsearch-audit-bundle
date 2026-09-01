<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Metadata;

/**
 * What to record for one entity class — the common form both AuditableInterface
 * and the attributes are reduced to.
 *
 * @internal what AuditMetadataFactory reads out of a declaration
 */
final class AuditMetadata
{
    /**
     * @param array<string, (callable(object): mixed)|null> $fields              property => representer for associations, null for scalars
     * @param list<string>                                  $alwaysRecorded
     * @param array<string, bool|list<string>>              $trackedCollections  to-many field => true for every changed field of an element, or the fields to take
     */
    public function __construct(
        public readonly string $objectType,
        public readonly array $fields,
        public readonly array $alwaysRecorded = [],
        public readonly array $trackedCollections = [],
    ) {
        foreach ($alwaysRecorded as $field) {
            if (!\array_key_exists($field, $fields)) {
                throw new \InvalidArgumentException(sprintf('"%s" is listed as always recorded but is not an audited field.', $field));
            }
        }

        foreach ($trackedCollections as $field => $tracked) {
            if (!\array_key_exists($field, $fields)) {
                throw new \InvalidArgumentException(sprintf('"%s" tracks its elements but is not an audited field.', $field));
            }

            // Both declarations arrive here, so this is where the rules belong: the
            // attribute refused an empty list of fields and the interface did not, and an
            // entity that declared one got no elements tracked and no explanation either.
            if ($tracked !== true && $tracked !== false && $tracked === []) {
                throw new \InvalidArgumentException(sprintf('"%s" tracks no field of its elements; name the fields, or pass true for every one that changed.', $field));
            }

            // Through the interface these arrive at runtime from application code, so
            // the declared type is a promise and not a fact; the closure takes mixed
            // because that is what may actually turn up.
            $unnamed = static fn (mixed $name): bool => !\is_string($name) || $name === '';

            if (\is_array($tracked) && array_filter($tracked, $unnamed) !== []) {
                throw new \InvalidArgumentException(sprintf('"%s" tracks a field of its elements whose name is not a name.', $field));
            }
        }
    }

    public function isAlwaysRecorded(string $field): bool
    {
        return \in_array($field, $this->alwaysRecorded, true);
    }

    /**
     * Which fields of an element of this collection are recorded: true for every one
     * that changed, a list for those named, null when the collection is not tracked.
     *
     * @return bool|list<string>|null
     */
    public function trackedElementFields(string $field): bool|array|null
    {
        $tracked = $this->trackedCollections[$field] ?? null;

        return $tracked === false ? null : $tracked;
    }

    /**
     * The to-many fields whose elements are watched.
     *
     * @return list<string>
     */
    public function trackedCollections(): array
    {
        return array_values(array_filter(array_keys($this->trackedCollections), fn (string $f): bool => $this->trackedElementFields($f) !== null));
    }
}
