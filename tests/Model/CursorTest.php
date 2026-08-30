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

        self::assertSame($sort, Cursor::decode(Cursor::encode($sort)));
    }

    public function testNoTokenNeedsEscapingInAUrl(): void
    {
        // Plain base64 would hand out + and /, which a query string then mangles.
        for ($i = 0; $i < 200; ++$i) {
            $token = Cursor::encode([sprintf('2026-08-%02d 10:00:%02d', $i % 28 + 1, $i % 60), 'entry-'.$i, $i]);

            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
            self::assertSame($token, rawurlencode($token));
        }
    }

    public function testAPaddedOrSpacedTokenIsStillRead(): void
    {
        $sort = ['2026-08-30 10:00:00', 'entry-19'];
        $plain = base64_encode(json_encode($sort, JSON_THROW_ON_ERROR)); // padding, + and / included

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
    }

    #[DataProvider('damagedTokens')]
    public function testADamagedTokenIsRefusedByName(string $token): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('not a cursor the reader handed out');

        Cursor::decode($token);
    }

    public function testAQueryContinuesFromAToken(): void
    {
        $token = Cursor::encode(['2026-08-30 10:00:00', 'entry-19']);

        $query = AuditQuery::for('order')->afterToken($token);

        self::assertTrue($query->usesCursor());
        self::assertEquals(AuditQuery::for('order')->after(['2026-08-30 10:00:00', 'entry-19']), $query);
    }

    public function testAQueryRefusesADamagedTokenBeforeTheClusterSeesIt(): void
    {
        $this->expectException(InvalidQueryException::class);

        AuditQuery::for('order')->afterToken('nonsense');
    }
}
