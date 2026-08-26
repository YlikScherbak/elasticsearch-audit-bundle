<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * One audited field going from an old value to a new one.
 *
 * Stored in the document as {"old": ..., "new": ...}, which is what a history
 * screen needs to render a diff without knowing anything about the field.
 */
final class Change
{
    public function __construct(
        public readonly mixed $old,
        public readonly mixed $new,
    ) {
    }

    /**
     * @return array{old: mixed, new: mixed}
     */
    public function toArray(): array
    {
        return ['old' => self::normalize($this->old), 'new' => self::normalize($this->new)];
    }

    /**
     * Recognises the array shape a Change is stored as, so documents read back
     * from Elasticsearch (or built by hand) can be handled the same way.
     *
     * @phpstan-assert-if-true array{old: mixed, new: mixed} $value
     */
    public static function isPair(mixed $value): bool
    {
        return \is_array($value) && \array_key_exists('old', $value) && \array_key_exists('new', $value);
    }

    /**
     * Dates are serialised the way the index mapping expects them; everything else
     * is left to json_encode.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (\is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
