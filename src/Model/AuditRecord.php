<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * One entry in the audit log: what object, which event, when, by whom, and what changed.
 *
 * Immutable — every with*() returns a copy. The writer fills in the actor and the
 * timestamp when the caller leaves them out, enrichers add top-level attributes,
 * and toDocument() produces the body that goes to Elasticsearch.
 */
final class AuditRecord
{
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param array<string, Change|mixed> $changes    field => Change, or any JSON-able value
     * @param array<string, mixed>        $attributes extra top-level, filterable fields
     * @param string|null                 $id         the document id; the writer assigns a UUID v7 when left out
     */
    public function __construct(
        public readonly string $objectType,
        public readonly int|string $objectId,
        public readonly string $event,
        public readonly ?\DateTimeImmutable $loggedAt = null,
        public readonly ?string $actor = null,
        public readonly array $changes = [],
        public readonly array $attributes = [],
        public readonly ?string $id = null,
    ) {
        if ($objectType === '') {
            throw new \InvalidArgumentException('An audit record needs a non-empty object type.');
        }

        if ($event === '') {
            throw new \InvalidArgumentException('An audit record needs a non-empty event.');
        }
    }

    public function withLoggedAt(\DateTimeImmutable $loggedAt): self
    {
        return new self($this->objectType, $this->objectId, $this->event, $loggedAt, $this->actor, $this->changes, $this->attributes, $this->id);
    }

    /**
     * The document id. Set it yourself only when you have a natural one; otherwise
     * the writer's UUID v7 gives you time-ordered ids and retry-safe writes for free.
     */
    public function withId(string $id): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('An audit record id cannot be empty.');
        }

        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $this->changes, $this->attributes, $id);
    }

    public function withActor(?string $actor): self
    {
        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $actor, $this->changes, $this->attributes, $this->id);
    }

    /**
     * @param array<string, Change|mixed> $changes
     */
    public function withChanges(array $changes): self
    {
        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $changes, $this->attributes, $this->id);
    }

    public function withChange(string $field, mixed $old, mixed $new): self
    {
        return $this->withChanges($this->changes + [$field => new Change($old, $new)]);
    }

    /**
     * Adds (or overrides) top-level attributes. These land beside objectType/event/...
     * in the document and are therefore filterable, unlike anything inside "changes".
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        foreach (array_keys($attributes) as $name) {
            if (\in_array($name, self::reservedFields(), true)) {
                throw new \InvalidArgumentException(sprintf('"%s" is a reserved document field and cannot be used as an attribute.', $name));
            }
        }

        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $this->changes, array_replace($this->attributes, $attributes), $this->id);
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    /**
     * The document body as stored in Elasticsearch.
     *
     * The layout — and in particular the "source" field holding the actor — is
     * shared with the index mapping the bundle creates, so records written here
     * can be read back by AuditReader without a translation layer.
     *
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        if ($this->loggedAt === null) {
            throw new \LogicException('The record has no timestamp yet; AuditWriter sets it before sending.');
        }

        $changes = [];

        foreach ($this->changes as $field => $change) {
            $changes[$field] = $change instanceof Change ? $change->toArray() : $change;
        }

        $document = [
            'objectType' => $this->objectType,
            'objectId' => $this->objectId,
            'event' => $this->event,
            'loggedAt' => $this->loggedAt->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE_FORMAT),
            'source' => $this->actor,
            'changes' => $changes,
        ];

        if ($this->id !== null) {
            $document['id'] = $this->id;
        }

        return $document + $this->attributes;
    }

    /**
     * @return list<string>
     */
    public static function reservedFields(): array
    {
        return ['id', 'objectType', 'objectId', 'event', 'loggedAt', 'source', 'changes'];
    }
}
