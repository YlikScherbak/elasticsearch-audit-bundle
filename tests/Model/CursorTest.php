<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Model;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Model\Cursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cursor a client carries: opaque, safe in a URL, and refused rather than
 * half-understood when it comes back damaged.
 */
final class CursorTest extends TestCase
{
    public function testWhatGoesInComesOut(): void
    {
        $sort = ['2026-08-30 10:00:00', 'entry-19', 42];

        self::assertSame($sort, Cursor::decode(Cursor::encode($sort, 'fp')));
    }

    public function testNoTokenNeedsEscapingInAUrl(): void
    {
        // Plain base64 would hand out + and /, which a query string then mangles.
        for ($i = 0; $i < 200; ++$i) {
            $token = Cursor::encode([sprintf('2026-08-%02d 10:00:%02d', $i % 28 + 1, $i % 60), 'entry-'.$i, $i], 'fp');

            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
            self::assertSame($token, rawurlencode($token));
        }
    }

    public function testAPaddedOrSpacedTokenIsStillRead(): void
    {
        $sort = ['2026-08-30 10:00:00', 'entry-19'];
        // A payload picked so the plain encoding really carries all three: padding, + and /.
        $plain = base64_encode(json_encode(['v' => 2, 's' => $sort, 'q' => '?>?a~~'], JSON_THROW_ON_ERROR));

        self::assertMatchesRegularExpression('/[+]/', $plain);
        self::assertMatchesRegularExpression('#[/]#', $plain);
        self::assertStringEndsWith('=', $plain);

        self::assertSame($sort, Cursor::decode($plain), 'a client that kept the plain form still gets its page');
        self::assertSame($sort, Cursor::decode(strtr($plain, '+', ' ')), 'and one whose URL turned + into a space');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damagedTokens(): iterable
    {
        yield 'not base64' => ['*** not a cursor ***'];
        yield 'not json' => [rtrim(strtr(base64_encode('half a token'), '+/', '-_'), '=')];
        yield 'an object, not sort values' => [rtrim(strtr(base64_encode('{"page":2}'), '+/', '-_'), '=')];
        yield 'an empty list' => [rtrim(strtr(base64_encode('[]'), '+/', '-_'), '=')];
        yield 'nothing at all' => [''];
        yield 'a list inside the list' => [rtrim(strtr(base64_encode('[["a"],"b"]'), '+/', '-_'), '=')];
        yield 'an object inside the list' => [rtrim(strtr(base64_encode('[{"gte":0},"b"]'), '+/', '-_'), '=')];
        // Well-formed all the way through, just enormous: a cursor is a handful of short
        // sort values, and the length boundary turns the rest away before decoding it.
        yield 'far longer than any cursor' => [rtrim(strtr(base64_encode(json_encode(array_fill(0, 500, 'aaaaaaaaaaaaaaaa'), JSON_THROW_ON_ERROR)), '+/', '-_'), '=')];
    }

    /**
     * What decode() checks is the token's shape, not its provenance — which is what the
     * message now says. A token a client made up moves it inside its own authorised
     * query and nowhere else; the extensions still decide what that query may see.
     */
    #[DataProvider('damagedTokens')]
    public function testADamagedTokenIsRefusedByName(string $token): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('The cursor token is malformed');

        Cursor::decode($token);
    }

    public function testATokenCarriesItsVersionSoAFutureShapeCanBeToldApart(): void
    {
        // The token is opaque to the client, and that promise is only worth something
        // if the bundle can recognise its own older shapes. A version marker is the
        // difference between "this cursor is from the previous format" and a tuple
        // silently read as something it is not.
        $payload = json_decode(base64_decode(strtr(Cursor::encode(['2026-08-30 10:00:00', 'entry-19'], 'fingerprint'), '-_', '+/'), true) ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['v']);
        self::assertSame(['2026-08-30 10:00:00', 'entry-19'], $payload['s']);
        self::assertSame('fingerprint', $payload['q']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tokensFromBeforeTheQueryWasCarried(): iterable
    {
        yield 'the bare array, before tokens were versioned' => [json_encode(['2026-08-30 10:00:00', 'entry-19'], JSON_THROW_ON_ERROR)];
        yield 'version 1, versioned but unbound' => [json_encode(['v' => 1, 's' => ['2026-08-30 10:00:00', 'entry-19']], JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('tokensFromBeforeTheQueryWasCarried')]
    public function testATokenThatCannotSayWhichQueryItCameFromIsRefused(string $payload): void
    {
        // Both shapes decode into a perfectly usable sort tuple, which is the problem:
        // read on a query they were not issued for, they answer with the page after
        // that position in a different result set and skip everything before it in
        // silence. 1.0 would rather cost one client the page it is holding.
        $legacy = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('older version');

        Cursor::decode($legacy);
    }

    public function testATokenFromAFutureVersionIsRefusedRatherThanGuessed(): void
    {
        $future = rtrim(strtr(base64_encode(json_encode(['v' => 99, 's' => ['x']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('newer version');

        Cursor::decode($future);
    }

    public function testANullSortValueIsRefusedRatherThanPagedOver(): void
    {
        // Elasticsearch hands out null for a record with no value for a sort field — a
        // record written before audit records carried ids. That tuple was accepted so
        // those pages would not be stranded, and the price is worse than the problem:
        // two such records in one index, saved in the same second, are the same position
        // to search_after, and one of them is stepped over without a word. No token is
        // issued for a position that cannot be continued from.
        try {
            Cursor::encode(['2026-08-30 10:00:00', null, 42], 'fp');
            self::fail('a cursor was issued for a tuple with no order after it');
        } catch (InvalidQueryException $refused) {
            self::assertStringContainsString('reindexed with ids', $refused->getMessage());
        }

        // And the same on the way in, for a token from before this rule.
        $this->expectException(InvalidQueryException::class);

        Cursor::decode(rtrim(strtr(base64_encode(json_encode(['v' => 2, 's' => ['2026-08-30 10:00:00', null], 'q' => 'fp'], JSON_THROW_ON_ERROR)), '+/', '-_'), '='));
    }

    public function testAQueryContinuesFromAToken(): void
    {
        $token = Cursor::encode(['2026-08-30 10:00:00', 'entry-19'], 'fp');

        $query = AuditQuery::for('order')->afterToken($token);

        self::assertTrue($query->usesCursor());
        self::assertSame(['2026-08-30 10:00:00', 'entry-19'], $query->searchAfter);

        // And it remembers which query the token was issued for. after() takes bare sort
        // values and has nothing to remember, which is the difference between the two:
        // one is a position a client was handed, the other a position the caller states.
        self::assertSame('fp', $query->continuedQuery());
        self::assertNull(AuditQuery::for('order')->after(['2026-08-30 10:00:00', 'entry-19'])->continuedQuery());
    }

    public function testAQueryRefusesADamagedTokenBeforeTheClusterSeesIt(): void
    {
        $this->expectException(InvalidQueryException::class);

        AuditQuery::for('order')->afterToken('nonsense');
    }

    public function testEncodeRefusesWhatDecodeWouldCallMalformed(): void
    {
        // The two sides used to disagree: encode() checked only that json_encode could
        // run, so the bundle could hand a client a token and then refuse it. Whatever
        // decode() will not accept is not a cursor, and saying so where it is built
        // names the page that produced it instead of the request that carried it back.
        foreach ([[], [['nested']], [new \stdClass()]] as $unusable) {
            try {
                Cursor::encode($unusable, 'fp');
                self::fail('encode() accepted '.json_encode($unusable));
            } catch (InvalidQueryException) {
            }
        }

        // And what decode() does accept still round-trips.
        self::assertSame(['2026-08-30 10:00:00', 7, 'audit_log'], Cursor::decode(Cursor::encode(['2026-08-30 10:00:00', 7, 'audit_log'], 'fp')));
    }
}
