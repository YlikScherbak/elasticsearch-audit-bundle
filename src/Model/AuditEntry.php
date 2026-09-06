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
     * @param list<string>         $warnings   what this entry had to invent while reading the
     *                                         document, in plain words; empty for every document
     *                                         the bundle itself wrote
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
        public readonly array $warnings = [],
    ) {
    }

    /**
     * Whether anything in this entry was invented rather than read.
     *
     * Leniency has a cost that was being paid silently: a timestamp nobody can parse
     * reads as the epoch, and the epoch is a real-looking date — it sorts, it exports,
     * it draws on a chart, and nothing about it says "this document was damaged". A
     * screen can now say so, and an export can leave those rows out.
     */
    public function isComplete(): bool
    {
        return $this->warnings === [];
    }

    /**
     * Hydration is lenient by policy, and this is where the policy lives: writing is
     * strict — the mapping refuses what does not fit — but reading meets whatever the
     * index actually holds (documents from another tool, a mangling reindex, a legacy
     * format), and one bad document must not turn a page of good ones into an
     * exception. A missing field reads as its empty value, and a timestamp that cannot
     * be parsed reads as the epoch — present, out of the way, and named in $warnings,
     * because the epoch is a real-looking date and a reader who is not told will take
     * it for one.
     *
     * @param array<string, mixed> $hit one element of hits.hits
     */
    public static function fromHit(array $hit): self
    {
        // Read as "nothing" when it is not a map, rather than trusted because it is
        // usually one. The policy below is that one damaged document must not break a
        // page, and array_diff_key() on a string would have broken the page anyway —
        // the check the rest of this method already applies to every field, applied to
        // the envelope holding them.
        $readable = \is_array($hit['_source'] ?? null);
        $source = $readable ? $hit['_source'] : [];
        $base = AuditRecord::reservedFields();

        // What had to be invented, said out loud. The values below are unchanged — a
        // page of history still renders — but "we could not read this" is a fact about
        // the record, and keeping it to ourselves is how a damaged document passes for
        // an ordinary one.
        $warnings = [];

        if (!$readable) {
            $warnings[] = 'The document has no readable body, so every field of this entry is empty.';
        }

        if (!self::isReadableTimestamp($source['loggedAt'] ?? null)) {
            $warnings[] = 'The logged-at value could not be read, so this entry is dated 1970-01-01. It is not when this happened.';
        }

        return new self(
            id: self::text($hit['_id'] ?? null),
            objectType: self::text($source['objectType'] ?? null),
            objectId: \is_int($source['objectId'] ?? null) ? $source['objectId'] : self::text($source['objectId'] ?? null),
            event: self::text($source['event'] ?? null),
            loggedAt: self::loggedAt($source['loggedAt'] ?? null),
            // Unreadable is nobody rather than "": the field is nullable, and null is
            // what "this document does not tell us who" already means to every caller.
            actor: \is_scalar($source['source'] ?? null) ? (string) $source['source'] : null,
            changes: \is_array($source['changes'] ?? null) ? $source['changes'] : [],
            attributes: array_diff_key($source, array_fill_keys($base, true)),
            sort: array_values(\is_array($hit['sort'] ?? null) ? $hit['sort'] : []),
            warnings: $warnings,
        );
    }

    /**
     * Whether the stored timestamp is one that can be read at all — asked separately
     * from reading it, because the answer is what the entry has to admit to.
     */
    private static function isReadableTimestamp(mixed $stored): bool
    {
        if (!\is_string($stored) || $stored === '') {
            return false;
        }

        try {
            new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * A stored value as the string this field is supposed to hold.
     *
     * A plain cast is not lenient, it only looks it: `(string) ['order']` is an "Array
     * to string conversion" warning, and an error handler that promotes warnings — the
     * Symfony one, in debug and in plenty of production setups — turns it into the
     * exception this whole policy exists to avoid. Anything that is not a string a
     * field can hold reads as empty, like a missing one.
     */
    private static function text(mixed $stored): string
    {
        return \is_scalar($stored) ? (string) $stored : '';
    }

    private static function loggedAt(mixed $stored): \DateTimeImmutable
    {
        if (\is_string($stored) && $stored !== '') {
            try {
                return new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                // fall through: a value nobody can read is a value that is not there
            }
        }

        return new \DateTimeImmutable('1970-01-01 00:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * The entry with its changes replaced — what a decorator returns when it makes the
     * change itself readable rather than adding something beside it: a permission name
     * in place of its key, a status label in place of its code. extra() is for what the
     * record does not have; this is for what it has in a form nobody wants to read.
     *
     * @param array<string, mixed> $changes
     */
    public function withChanges(array $changes): self
    {
        return new self($this->id, $this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $changes, $this->attributes, $this->extra, $this->sort, $this->warnings);
    }

    /**
     * The entry with something added beside its own fields — a name looked up for the
     * actor, a title for the object — under "extra", which is never stored.
     *
     * @param array<string, mixed> $extra
     */
    public function withExtra(array $extra): self
    {
        return new self($this->id, $this->objectType, $this->objectId, $this->event, $this->loggedAt, $this->actor, $this->changes, $this->attributes, array_replace($this->extra, $extra), $this->sort, $this->warnings);
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * The entry in the shape it has in Elasticsearch — "source" for the actor, the
     * stored timestamp format, and no extra: what a decorator added on the read never
     * pretends to be stored. toArray() is the other one — "actor", ISO 8601, extra
     * winning over attributes — and the difference between the two is deliberate.
     *
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'id' => $this->id,
            'objectType' => $this->objectType,
            'objectId' => $this->objectId,
            'event' => $this->event,
            'loggedAt' => $this->loggedAt->setTimezone(new \DateTimeZone('UTC'))->format(AuditRecord::DATE_FORMAT),
            'source' => $this->actor,
            'changes' => $this->changes,
        ] + $this->attributes;
    }

    /**
     * A JSON-friendly array: what an API endpoint returns for one line of history.
     *
     * Extra outranks a stored attribute of the same name — deliberately, and
     * deliberately only here: extra is read-side enrichment (a decorator turning a
     * country code into its name), and toArray() is the read-side shape, while
     * toDocument() is the stored shape and never sees extra at all. Do not "align"
     * the two methods: their difference is the point. Base fields yield to neither.
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
        ]
            // Only when there is something to say. Every document this bundle wrote
            // reads back complete, so the key would otherwise be an empty list on every
            // row of every page — and a shape that is always there stops being read.
            + ($this->warnings === [] ? [] : ['warnings' => $this->warnings])
            + $this->extra + $this->attributes;
    }
}
