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
     * @param list<mixed> $sortValues
     */
    public static function encode(array $sortValues): string
    {
        try {
            $json = json_encode($sortValues, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidQueryException('The sort values of this page cannot be encoded as a cursor: '.$e->getMessage(), 0, $e);
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return list<mixed> the sort values, ready for AuditQuery::after()
     *
     * @throws InvalidQueryException the token is not a valid encoded cursor — what it says
     *                               is checked, where it came from is not
     */
    public static function decode(string $token): array
    {
        // Padding is dropped when encoding and is not required to decode, but a client
        // that keeps it (or a "+" a URL turned into a space) still gets its page.
        $binary = base64_decode(strtr(trim($token), '-_ ', '+/+'), true);

        if ($binary === false) {
            throw self::invalid();
        }

        try {
            $values = json_decode($binary, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid();
        }

        if (!\is_array($values) || $values === [] || !array_is_list($values)) {
            throw self::invalid();
        }

        return $values;
    }

    private static function invalid(): InvalidQueryException
    {
        return new InvalidQueryException('The cursor token is malformed. Pass AuditPage::nextCursorToken() back unchanged, or start from the first page.');
    }
}
