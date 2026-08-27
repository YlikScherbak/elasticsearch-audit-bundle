<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * One history entry as read back from Elasticsearch: the record's fields, its
 * document id, and whatever decorators attached (the actor's display name, the
 * related order's title...) under "extra".
 */
final class AuditEntry
{
    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $attributes top-level fields beyond the base ones
     * @param array<string, mixed> $extra      added by RecordDecorators, never stored
     * @param list<mixed>          $sort       the sort values Elasticsearch returned — the cursor
     */
    public function __construct(
        public readonly string $id,
        public readonly string $objectType,
        public readonly int|string $objectId,
        public readonly string $event,
        public readonly \DateTimeImmutable $loggedAt,
        public readonly ?string $actor,
        public readonly array $changes = [],
        public readonly array $attributes = [],
        public readonly array $extra = [],
        public readonly array $sort = [],
    ) {
    }

    /**
     * @param array<string, mixed> $hit one element of hits.hits
     */
    public static function fromHit(array $hit): self
    {
        $source = $hit['_source'] ?? [];
        $base = AuditRecord::reservedFields();

        return new self(
            id: (string) $hit['_id'],
            objectType: (string) ($source['objectType'] ?? ''),
            objectId: \is_int($source['objectId'] ?? null) ? $source['objectId'] : (string) ($source['objectId'] ?? ''),
            event: (string) ($source['event'] ?? ''),
            loggedAt: new \DateTimeImmutable((string) ($source['loggedAt'] ?? '1970-01-01 00:00:00'), new \DateTimeZone('UTC')),
            actor: isset($source['source']) ? (string) $source['source'] : null,
            changes: \is_array($source['changes'] ?? null) ? $source['changes'] : [],
            attributes: array_diff_key($source, array_fill_keys($base, true)),
            sort: array_values(\is_array($hit['sort'] ?? null) ? $hit['sort'] : []),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function withExtra(array $extra): self
    {
        return new self($this->id, $this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $this->changes, $this->attributes, array_replace($this->extra, $extra), $this->sort);
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * A JSON-friendly array: what an API endpoint returns for one line of history.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'objectType' => $this->objectType,
            'objectId' => $this->objectId,
            'event' => $this->event,
            'loggedAt' => $this->loggedAt->format(\DATE_ATOM),
            'actor' => $this->actor,
            'changes' => $this->changes,
        ] + $this->attributes + $this->extra;
    }
}
