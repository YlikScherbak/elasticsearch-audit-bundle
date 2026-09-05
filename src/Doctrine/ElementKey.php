<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

/**
 * The flattened key a tracked collection element is recorded under.
 *
 * Two shapes, and they must never spell the same thing:
 *
 *   lines.42            an element came or went (membership)
 *   lines.42.quantity   a field of that element changed
 *
 * Identifiers may be arbitrary strings, so an element whose id is "42.quantity"
 * would write a membership key indistinguishable from element 42's quantity change,
 * and one would overwrite the other inside a single flush. The id segment is escaped
 * for that reason — the same reason a composite objectId escapes its own delimiter.
 * An id containing neither a dot nor a backslash is written exactly as it always was.
 *
 * @internal how AuditSubscriber and ChangeSetBuilder name element changes
 */
final class ElementKey
{
    /**
     * The membership key: this element belongs to (or has left) the collection.
     */
    public static function of(string $collectionField, int|string $elementId): string
    {
        return $collectionField.'.'.self::escape($elementId);
    }

    /**
     * The key for one changed field of one element.
     */
    public static function field(string $collectionField, int|string $elementId, string $field): string
    {
        return self::of($collectionField, $elementId).'.'.$field;
    }

    private static function escape(int|string $elementId): string
    {
        return str_replace(['\\', '.'], ['\\\\', '\\.'], (string) $elementId);
    }
}
