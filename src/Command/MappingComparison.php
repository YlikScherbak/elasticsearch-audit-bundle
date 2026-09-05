<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Command;

/**
 * What an index's mapping lacks or contradicts, against what the definition declares.
 *
 * Whatever the definition declares has to hold in the index: the type, the options
 * behind it, and the fields inside an object. A date whose format drifted refuses
 * every document the writer sends, and a nested field that was never mapped filters
 * to nothing — both fail exactly like a wrong top-level type, and neither is visible
 * to a comparison that stops at the type.
 *
 * The walk is one-directional: an option the index has and the definition never named
 * is an Elasticsearch default, not drift.
 *
 * @internal shared by audit:check (which reports) and audit:index:sync (which adds what is missing)
 */
final class MappingComparison
{
    /**
     * @param list<string> $missing    dotted paths the index does not map at all — what sync can add
     * @param list<string> $mismatched what is mapped otherwise than declared — a reindex, not an addition
     */
    private function __construct(
        public readonly array $missing,
        public readonly array $mismatched,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $expected
     * @param array<string, mixed>                $actual
     */
    public static function between(array $expected, array $actual): self
    {
        $missing = [];
        $mismatched = [];
        self::walk('', $expected, $actual, $missing, $mismatched);

        return new self($missing, $mismatched);
    }

    public function clean(): bool
    {
        return $this->missing === [] && $this->mismatched === [];
    }

    /**
     * @param array<string, array<string, mixed>> $expected
     * @param array<string, mixed>                $actual
     * @param list<string>                        $missing
     * @param list<string>                        $mismatched
     */
    private static function walk(string $prefix, array $expected, array $actual, array &$missing, array &$mismatched): void
    {
        foreach ($expected as $field => $property) {
            $path = $prefix.$field;

            if (!\is_array($actual[$field] ?? null)) {
                $missing[] = $path;

                continue;
            }

            $found = $actual[$field];

            // An object needs no "type" in the mapping — a field holding properties and
            // nothing else is an object on both sides of the comparison.
            $expectedType = $property['type'] ?? (isset($property['properties']) ? 'object' : null);
            $actualType = $found['type'] ?? (isset($found['properties']) ? 'object' : null);

            if ($expectedType !== null && $actualType !== $expectedType) {
                $mismatched[] = sprintf('%s is %s, expected %s', $path, $actualType ?? 'an object', $expectedType);

                continue; // under a wrong type, every option is noise
            }

            foreach ($property as $option => $value) {
                if ($option === 'type' || $option === 'properties') {
                    continue;
                }

                if (!\array_key_exists($option, $found)) {
                    $mismatched[] = sprintf('%s %s is not set, expected %s', $path, $option, self::describe($value));
                } elseif ($found[$option] !== $value) {
                    $mismatched[] = sprintf('%s %s is %s, expected %s', $path, $option, self::describe($found[$option]), self::describe($value));
                }
            }

            if (\is_array($property['properties'] ?? null)) {
                self::walk($path.'.', $property['properties'], \is_array($found['properties'] ?? null) ? $found['properties'] : [], $missing, $mismatched);
            }
        }
    }

    private static function describe(mixed $value): string
    {
        return \is_string($value) ? '"'.$value.'"' : json_encode($value, \JSON_THROW_ON_ERROR);
    }
}
