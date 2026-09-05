<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

/**
 * Settings and mapping of an audit index: the fields every record has, plus
 * whatever the application's enrichers add on top.
 *
 * "changes" is deliberately not indexed (enabled: false). Its shape differs per
 * object type and per field, and indexing it would blow the mapping up over
 * time; anything worth filtering on belongs in a top-level attribute instead.
 *
 * The mapping is "dynamic: false": a field nobody declared is stored with the
 * document but not indexed, instead of Elasticsearch guessing a type for it (text
 * for what should be a keyword, long for what later turns out to be a string and
 * then rejects the document). Enrichers declare their fields in mapping(), and
 * audit:check reports a field that is missing or mapped with another type.
 */
final class IndexDefinition
{
    public const OBJECT_ID_KEYWORD = 'keyword';
    public const OBJECT_ID_INTEGER = 'integer';
    /** Elasticsearch's "integer" is 32 bits; a bigint primary key needs this one. */
    public const OBJECT_ID_LONG = 'long';

    /**
     * @param array<string, array<string, mixed>> $properties additional mapping properties
     * @param array<string, mixed>                $settings
     */
    public function __construct(
        private readonly string $objectIdType = self::OBJECT_ID_KEYWORD,
        private readonly array $properties = [],
        private readonly array $settings = ['number_of_shards' => 1, 'number_of_replicas' => 1],
    ) {
        if (!\in_array($objectIdType, [self::OBJECT_ID_KEYWORD, self::OBJECT_ID_INTEGER, self::OBJECT_ID_LONG], true)) {
            throw new \InvalidArgumentException(sprintf('objectId can be mapped as "keyword", "integer" or "long", not "%s".', $objectIdType));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     */
    public function withProperties(array $properties): self
    {
        foreach (array_keys($properties) as $name) {
            if (\array_key_exists($name, $this->baseProperties())) {
                throw new \InvalidArgumentException(sprintf('"%s" is a base field of every audit record; its mapping cannot be overridden.', $name));
            }
        }

        return new self($this->objectIdType, array_replace($this->properties, $properties), $this->settings);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function withSettings(array $settings): self
    {
        return new self($this->objectIdType, $this->properties, array_replace($this->settings, $settings));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function properties(): array
    {
        return $this->baseProperties() + $this->properties;
    }

    /**
     * The body of an indices.create request.
     *
     * @return array{settings: array<string, mixed>, mappings: array{dynamic: false, properties: array<string, array<string, mixed>>}}
     */
    public function toArray(): array
    {
        return [
            'settings' => $this->settings,
            'mappings' => ['dynamic' => false, 'properties' => $this->properties()],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function baseProperties(): array
    {
        return [
            'id' => ['type' => 'keyword'],
            'objectType' => ['type' => 'keyword'],
            'objectId' => ['type' => $this->objectIdType],
            'event' => ['type' => 'keyword'],
            'loggedAt' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
            'source' => ['type' => 'keyword'],
            'changes' => ['type' => 'object', 'enabled' => false],
        ];
    }
}
