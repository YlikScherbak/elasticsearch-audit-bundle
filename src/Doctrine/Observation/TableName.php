<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

/**
 * A table as a statement names it: its parts, schema first, each with whether it was quoted -- so
 * that a dot inside a quoted name stays the name's, not a schema's.
 *
 * @internal
 */
final class TableName
{
    /**
     * @param non-empty-list<array{string, bool}> $parts each part's text, unquoted, and whether it was quoted
     */
    public function __construct(public readonly array $parts)
    {
    }

    /** The name as the statement wrote it, parts joined by dots: what the mapping is matched by. */
    public function written(): string
    {
        return implode('.', array_column($this->parts, 0));
    }

    /**
     * Whether this may be the mapped table, though not as the mapping spells it: by its last part,
     * whatever schema stands before it, and -- not quoted -- in any case, as a database folds an
     * unquoted name or ignores its case; quoted, exactly. Grounds for a doubt and nothing more: it
     * never says the statement wrote the mapping's table, and a table of another schema, or of
     * another case where the database keeps it, is said too.
     */
    public function mayBe(string $mapped): bool
    {
        [$name, $quoted] = $this->parts[\count($this->parts) - 1];
        $dot = strrpos($mapped, '.');
        $table = $dot === false ? $mapped : substr($mapped, $dot + 1);

        return $quoted ? $name === $table : strcasecmp($name, $table) === 0;
    }
}
