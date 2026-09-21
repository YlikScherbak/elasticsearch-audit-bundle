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
     * The field the write path adds to every document: when the write that produced
     * the version now in the index started. Named here because it is part of the
     * document's shape, and set there because a record cannot know it.
     *
     * @see \Borsche\ElasticsearchAuditBundle\Transport\WrittenAt
     */
    public const WRITTEN_AT = 'writtenAt';

    /**
     * @param array<string, Change|mixed> $changes    field => Change, or any JSON-able value
     * @param array<string, mixed>        $attributes extra top-level, filterable fields
     * @param string|null                 $id         the document id; the writer assigns a UUID v7 when left out
     * @param AuditOrigin                 $origin     which part of the application produced this; the bundle sets it
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
        public readonly AuditOrigin $origin = AuditOrigin::Manual,
    ) {
        if ($objectType === '') {
            throw new \InvalidArgumentException('An audit record needs a non-empty object type.');
        }

        if ($event === '') {
            throw new \InvalidArgumentException('An audit record needs a non-empty event.');
        }

        // The same rule withAttributes() applies, at the other door: an attribute
        // shadowing a base field would be silently dropped from the document, and the
        // caller would believe it was set.
        foreach (array_keys($attributes) as $name) {
            if (\in_array($name, self::reservedFields(), true)) {
                throw new \InvalidArgumentException(sprintf('"%s" is a reserved document field and cannot be used as an attribute.', $name));
            }

            // Refused rather than reserved, which is a different thing: the name is not
            // a base field — it is filterable like any attribute, which is the whole
            // point of it — but it is the write path's to set, and a record that set it
            // would be describing a write that has not happened yet. Silently replacing
            // it on the way out is the other option, and it is how somebody reads a
            // number they put there as one the bundle measured.
            if ($name === self::WRITTEN_AT) {
                throw new \InvalidArgumentException(sprintf('"%s" is set when the document is written and cannot be set on a record. Read it back and filter on it; the bundle fills it in.', $name));
            }
        }
    }

    public function withLoggedAt(\DateTimeImmutable $loggedAt): self
    {
        return new self($this->objectType, $this->objectId, $this->event, $loggedAt, $this->actor, $this->changes, $this->attributes, $this->id, $this->origin);
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

        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $this->changes, $this->attributes, $id, $this->origin);
    }

    public function withActor(?string $actor): self
    {
        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $actor, $this->changes, $this->attributes, $this->id, $this->origin);
    }

    /**
     * @param array<string, Change|mixed> $changes
     */
    public function withChanges(array $changes): self
    {
        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $changes, $this->attributes, $this->id, $this->origin);
    }

    public function withChange(string $field, mixed $old, mixed $new): self
    {
        return $this->withChanges($this->changes + [$field => new Change($old, $new)]);
    }

    /**
     * Like withAttributes(), for values that must not overwrite what is already there:
     * an enricher filling in a default the caller may have set itself, or a second
     * enricher that defers to the first. Reserved field names are refused either way.
     *
     * @param array<string, mixed> $attributes
     */
    public function withAddedAttributes(array $attributes): self
    {
        return $this->withAttributes(array_diff_key($attributes, $this->attributes));
    }

    /**
     * The record with these attributes set, replacing any of the same name. These land
     * beside objectType/event/... in the document and are therefore filterable, unlike
     * anything inside "changes". A reserved field name is refused — by the constructor,
     * where every path ends up.
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $this->changes, array_replace($this->attributes, $attributes), $this->id, $this->origin);
    }

    /**
     * Where this record came from. The bundle sets it: the Doctrine listener marks what
     * it builds, everything handed to the writer is the application's own.
     */
    public function withOrigin(AuditOrigin $origin): self
    {
        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $this->changes, $this->attributes, $this->id, $origin);
    }

    /**
     * The record without those attributes. Redaction uses it: an attribute is a mapped,
     * filterable field, so a masked one would be indexed as the placeholder and refused
     * outright where the mapping says integer — a value that must not be kept is not
     * kept, rather than kept as three asterisks.
     */
    public function withoutAttributes(string ...$names): self
    {
        $attributes = array_diff_key($this->attributes, array_fill_keys($names, true));

        if ($attributes === $this->attributes) {
            return $this;
        }

        return new self($this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $this->changes, $attributes, $this->id, $this->origin);
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
