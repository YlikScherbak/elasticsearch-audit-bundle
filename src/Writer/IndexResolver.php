<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

/**
 * Decides which index a record goes to: a per-object-type route when configured,
 * the default index otherwise. Lets a chatty type (stock movements, logins) live
 * in its own index without the application knowing about indices at all.
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
        return array_values(array_unique([$this->default, ...array_values($this->routing)]));
    }
}
