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
     * format is read as though it were this one.
     *
     * Version 2 is {"v":2,"s":[...],"q":"<query fingerprint>"}. The earlier shapes —
     * {"v":1,"s":[...]} and, before that, a bare array — carry no fingerprint, so a
     * reader cannot tell whether they belong to the query being continued; 1.0 refuses
     * them rather than answer from the middle of somebody else's result set.
     */
    private const VERSION = 2;

    /**
     * @param list<mixed> $sortValues
     * @param string      $query      the fingerprint of the query this page came from. Required, and
     *                                that is the point: a token that cannot say which result set it
     *                                is a position in cannot be checked when it comes back, and an
     *                                unchecked one answers from the middle of whatever is searched
     *                                next
     */
    public static function encode(array $sortValues, string $query): string
    {
        // Held to what decode() accepts, on the way out as well as on the way in. It
        // checked only that json_encode could run, so the bundle could hand a client a
        // token and refuse the same token a moment later — and the complaint would name
        // the request that carried it back rather than the page that produced it.
        self::assertUsable($sortValues);

        try {
            $json = json_encode(['v' => self::VERSION, 's' => $sortValues, 'q' => $query], JSON_THROW_ON_ERROR);
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

        // Only this version's envelope is read. A bare list is the shape tokens had
        // before they were versioned — it decodes into a perfectly usable sort tuple,
        // which is exactly why it has to be refused now: nothing in it says which query
        // it belongs to. Anything else without an envelope is not a token at all, and
        // saying "malformed" is more useful there than "old".
        if (array_is_list($values)) {
            throw self::looksLikeSortValues($values) ? self::fromAnotherVersion(null) : self::invalid();
        }

        if (!\array_key_exists('v', $values)) {
            throw self::invalid();
        }

        if ($values['v'] !== self::VERSION) {
            throw self::fromAnotherVersion($values['v']);
        }

        // Every token this bundle issues names the query it belongs to, and one that
        // does not is not a token it issued. The reader's check is skipped for a token
        // with no fingerprint — silently, which is the shape of the mistake this whole
        // envelope exists to prevent — so the shape is refused here instead of being
        // read as a cursor that answers anywhere.
        if (!\is_string($values['q'] ?? null) || $values['q'] === '') {
            throw new InvalidQueryException('This cursor token does not say which query it was issued for, so nothing can check whether continuing it here answers from the middle of a different result set. Pass back a token AuditPage::nextCursorToken() produced, or start from the first page.');
        }

        $values = $values['s'] ?? null;

        if (!\is_array($values) || $values === [] || !array_is_list($values)) {
            throw self::invalid();
        }

        // Sort values are scalars. A token smuggling a structure in is not a cursor,
        // whatever else it may be — and a null is not one either: it means the record has
        // no value for a field the query sorts by, and AuditQuery::after() explains why
        // that has no order to continue from.
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
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
            if (!is_scalar($value)) {
                // Including null. Elasticsearch answers with one for a record that has no
                // value for a sort field — a record from before audit records carried ids —
                // and a tuple like that names a position two records can share. No token is
                // issued for it rather than one that may step over a record.
                throw new InvalidQueryException(sprintf('A sort value is a scalar; %s cannot travel in a cursor token. A null means the last record on this page has no value for a field the query sorts by, which is a record from before audit records carried ids: there is no position after it that Elasticsearch can continue from, so those are paged by page number or reindexed with ids first.', get_debug_type($value)));
            }
        }
    }

    /**
     * Which query the token was issued for, when it says so.
     *
     * A cursor is a position inside a result set, and the reader has to be able to tell
     * that the set it is about to search is the one the token came from:
     * `withEvents(...)->afterToken($t)` is otherwise a page of somebody else's results,
     * silently missing everything before that position. Reading only — a malformed token
     * is decode()'s to refuse, and it will.
     */
    public static function queryOf(string $token): ?string
    {
        $binary = base64_decode(strtr(trim($token), '-_ ', '+/+'), true);

        if ($binary === false) {
            return null;
        }

        try {
            $values = json_decode($binary, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($values) && \is_string($values['q'] ?? null) ? $values['q'] : null;
    }

    /**
     * Whether a bare list is the sort tuple older tokens carried — a page somebody is
     * holding — rather than something that merely decoded into an array.
     *
     * @param array<mixed> $values
     */
    private static function looksLikeSortValues(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if ($value !== null && !\is_scalar($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A token whose envelope is not this version's. Which direction it came from is
     * worth saying: a newer one means the reader is behind, an older one means the
     * token is from before the bundle bound a cursor to its query. Neither is the
     * client's mistake, and in both cases the first page is the way out.
     */
    private static function fromAnotherVersion(mixed $version): InvalidQueryException
    {
        if (\is_int($version) && $version > self::VERSION) {
            return new InvalidQueryException(sprintf('This cursor token was issued in a newer version (%d) than this bundle understands (%d). Start from the first page.', $version, self::VERSION));
        }

        return new InvalidQueryException(sprintf('This cursor token was issued in an older version (%s) of the audit bundle, before a token carried the query it belongs to — there is no way to tell whether continuing it here would answer from the middle of a different result set. Start from the first page.', $version === null ? 'before tokens were versioned' : (\is_scalar($version) ? (string) $version : get_debug_type($version))));
    }

    private static function invalid(): InvalidQueryException
    {
        return new InvalidQueryException('The cursor token is malformed. Pass AuditPage::nextCursorToken() back unchanged, or start from the first page.');
    }
}
