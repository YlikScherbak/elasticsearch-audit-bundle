<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;

/**
 * The cursor of a page as one string a client can carry.
 *
 * What is inside — the sort values of the last entry — is the bundle's business, not
 * the client's: it hands the token back unread, so the contents can grow (a point in
 * time id, say) without a single change on the other side. The encoding is base64url,
 * so the token survives a query string without escaping.
 */
final class Cursor
{
    /**
     * The shape of what a token carries. A client never looks inside, but the bundle
     * has to recognise its own older tokens: without a marker, a payload from another
     * format is read as though it were this one. Version 1 is {"v":1,"s":[...]}; a
     * bare array is the unversioned form tokens had before, still accepted.
     */
    private const VERSION = 1;

    /**
     * @param list<mixed> $sortValues
     */
    public static function encode(array $sortValues): string
    {
        // Held to what decode() accepts, on the way out as well as on the way in. It
        // checked only that json_encode could run, so the bundle could hand a client a
        // token and refuse the same token a moment later — and the complaint would name
        // the request that carried it back rather than the page that produced it.
        self::assertUsable($sortValues);

        try {
            $json = json_encode(['v' => self::VERSION, 's' => $sortValues], JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidQueryException('The sort values of this page cannot be encoded as a cursor: '.$e->getMessage(), 0, $e);
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Far longer than any cursor the bundle issues — a sort tuple is a handful of short
     * values, and there is room left for it to grow — but a boundary: the token comes
     * from a query string, and this is what keeps a megabyte of it out of the decoder.
     */
    private const MAX_LENGTH = 4096;

    /**
     * @return non-empty-list<scalar|null> the sort values, ready for AuditQuery::after()
     *
     * @throws InvalidQueryException the token is not a valid encoded cursor — what it says
     *                               is checked, where it came from is not
     */
    public static function decode(string $token): array
    {
        $token = trim($token);

        if ($token === '' || \strlen($token) > self::MAX_LENGTH) {
            throw self::invalid();
        }

        // Padding is dropped when encoding and is not required to decode, but a client
        // that keeps it (or a "+" a URL turned into a space) still gets its page.
        $binary = base64_decode(strtr($token, '-_ ', '+/+'), true);

        if ($binary === false) {
            throw self::invalid();
        }

        try {
            $values = json_decode($binary, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid();
        }

        if (!\is_array($values)) {
            throw self::invalid();
        }

        // The versioned envelope, or the bare array tokens had before it — a client
        // holding one from yesterday keeps its page.
        if (\array_key_exists('v', $values)) {
            if ($values['v'] !== self::VERSION) {
                throw new InvalidQueryException(sprintf('This cursor token was issued in a newer version (%s) than this bundle understands (%d). Start from the first page.', \is_scalar($values['v']) ? (string) $values['v'] : get_debug_type($values['v']), self::VERSION));
            }

            $values = $values['s'] ?? null;

            if (!\is_array($values)) {
                throw self::invalid();
            }
        }

        if ($values === [] || !array_is_list($values)) {
            throw self::invalid();
        }

        // Sort values are scalars — and null, which legacy indices sort with. A token
        // smuggling a structure in is not a cursor, whatever else it may be.
        foreach ($values as $value) {
            if ($value !== null && !\is_scalar($value)) {
                throw self::invalid();
            }
        }

        return $values;
    }

    /**
     * The rules decode() applies, checked on the values before they are encoded.
     *
     * @param list<mixed> $sortValues
     */
    private static function assertUsable(array $sortValues): void
    {
        if ($sortValues === [] || !array_is_list($sortValues)) {
            throw new InvalidQueryException('A cursor is built from the sort values of a page, as a list, and there has to be at least one of them.');
        }

        foreach ($sortValues as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidQueryException(sprintf('A sort value is a scalar or null; %s cannot travel in a cursor token.', get_debug_type($value)));
            }
        }
    }

    private static function invalid(): InvalidQueryException
    {
        return new InvalidQueryException('The cursor token is malformed. Pass AuditPage::nextCursorToken() back unchanged, or start from the first page.');
    }
}
