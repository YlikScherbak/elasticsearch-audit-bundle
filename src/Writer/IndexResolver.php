<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * Decides which index a record goes to: a per-object-type route when configured,
 * the default index otherwise. Lets a chatty type (stock movements, logins) live
 * in its own index without the application knowing about indices at all.
 *
 * @internal routing comes from the indices configuration
 */
final class IndexResolver
{
    /**
     * @param array<string, string> $routing objectType => index
     */
    public function __construct(
        private readonly string $default,
        private readonly array $routing = [],
    ) {
        if ($default === '') {
            throw new \InvalidArgumentException('The default audit index name cannot be empty.');
        }
    }

    public function resolve(string $objectType): string
    {
        return $this->routing[$objectType] ?? $this->default;
    }

    /**
     * Where this record goes. The writer resolves through the whole record, not just
     * its type: today the answer only looks at objectType, but the record carries
     * loggedAt — which is what a time-based routing strategy (monthly indices) will
     * need, and widening this later would mean rewiring the write path. Reading stays
     * by type (a query has no record): rotation that must work today is served by a
     * write alias with ILM rollover — see "Retention" in the README.
     */
    public function resolveFor(AuditRecord $record): string
    {
        return $this->resolve($record->objectType);
    }

    public function default(): string
    {
        return $this->default;
    }

    /**
     * Every distinct index the configuration can route to — what `audit:index:create` creates.
     *
     * @return list<string>
     */
    public function all(): array
    {
        // Two statements rather than one, so that the two re-listings can be spoken
        // about separately. The inner one is belt: spreading an array renumbers its
        // integer keys and keeps its string ones, and array_unique() then preserves
        // whatever it was given, so the result is the same either way. The outer one is
        // the braces, and it is load-bearing: a repeated index anywhere but last leaves
        // a gap in the keys, and an array with a gap is not a list — it json_encodes as
        // an object, and every caller of this is declared against list<string>.
        $indices = array_unique([$this->default, ...array_values($this->routing)]);

        return array_values($indices);
    }
}
