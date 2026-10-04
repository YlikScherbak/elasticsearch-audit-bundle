<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\SqlDialect;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the log makes of a statement a comment was put on: it reads it without the comment --
 * a savepoint is a savepoint, a read is a read -- and keeps it as it ran. One whose comments
 * cannot be taken out is never passed over as a read.
 */
final class WhatACommentLeavesOfAStatementTest extends TestCase
{
    private const UPDATE = 'UPDATE Article SET title = ? WHERE id = ?';

    /** @return iterable<string, array{string, string, string}> */
    public static function savepoints(): iterable
    {
        yield 'a comment after each' => ['SAVEPOINT mine /* tag */', 'ROLLBACK TO SAVEPOINT mine /* tag */', 'RELEASE SAVEPOINT mine /* tag */'];
        yield 'a comment before each' => ['/* tag */ SAVEPOINT mine', '/* tag */ ROLLBACK TO SAVEPOINT mine', '/* tag */ RELEASE SAVEPOINT mine'];
        yield 'a line comment after each' => ['SAVEPOINT mine -- tag', 'ROLLBACK TO SAVEPOINT mine -- tag', 'RELEASE SAVEPOINT mine -- tag'];
    }

    #[DataProvider('savepoints')]
    public function testASavepointWithACommentIsASavepoint(string $savepoint, string $rollBack, string $release): void
    {
        $log = new StatementLog();
        $log->began();

        self::assertNull($log->executed($savepoint, [], 0), 'a savepoint has no place of its own');
        $dead = $log->executed(self::UPDATE, ['a', 1], 1);
        $log->executed($rollBack, [], 0);
        $log->executed($release, [], 0);
        $log->committed();

        self::assertNotNull($dead);
        self::assertSame(StatementLog::VOID, $log->fate($dead));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function reads(): iterable
    {
        yield 'a tagged read' => ['/* tag */ SELECT 1', true];
        yield 'a read tagged after' => ['SELECT 1 -- tag', true];
        yield 'a read with a hint inside' => ['SELECT /*+ INDEX(a) */ 1', true];
        yield 'an executable comment before a read' => ['/*!50000 SELECT */ SELECT 1', false];
        yield 'a hint before a read' => ['/*+ x */ SELECT 1', false];
        yield 'a tagged write' => ['/* tag */ '.self::UPDATE, false];
    }

    /**
     * A statement whose comments cannot be taken out is told as written: a read only when it
     * begins with one, and so never when something it cannot read stands before.
     */
    #[DataProvider('reads')]
    public function testAReadIsToldWithoutItsCommentsAndNeverPastOneItCannotRead(string $sql, bool $reads): void
    {
        self::assertSame($reads, (new StatementLog())->onlyReads($sql));
    }

    public function testTheLogKeepsAStatementAsItRanAndReadsItWithoutItsComment(): void
    {
        $log = new StatementLog();
        $at = $log->executed('/* tag */ '.self::UPDATE.' -- more', ['a', 1], 1);
        $unread = $log->executed('/*!50000 x */ '.self::UPDATE, ['a', 1], 1);
        self::assertNotNull($at);
        self::assertNotNull($unread);

        self::assertSame(['/* tag */ '.self::UPDATE.' -- more', '  '.self::UPDATE.'  '], [$log->statement($at)['sql'] ?? null, $log->statement($at)['read'] ?? null]);
        $kept = $log->statement($unread);
        self::assertNotNull($kept, 'a statement it cannot read is kept: it may be of any table');
        self::assertSame(['/*!50000 x */ '.self::UPDATE, null], [$kept['sql'], $kept['read']]);
    }

    public function testAKeyIsHandedOutForATaggedInsert(): void
    {
        $log = new StatementLog();
        $at = $log->executed('/* tag */ INSERT INTO Article (title) VALUES (?)', ['a'], 1);
        $log->keyHandedOut(7);

        self::assertNotNull($at);
        self::assertSame(7, $log->statement($at)['key'] ?? null);
    }

    public function testATaggedDeleteOfAWatchedKeyIsLookedAfterAndOneThatCannotBeReadIsNot(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], ['5' => true], 'SELECT article_id FROM article_tag WHERE tag_id = ?');

        self::assertCount(1, $log->toObserveAfter('/* tag */ DELETE FROM Tag WHERE id = ? -- more', [1 => 5], 1));
        self::assertSame([], $log->toObserveAfter('/*! x */ DELETE FROM Tag WHERE id = ?', [1 => 5], 1));
    }

    public function testTheDialectIsTheConnectionsAndTheLogReadsByIt(): void
    {
        // On MySQL `#` is a comment; elsewhere it is not.
        $log = new StatementLog();
        $log->speaks(SqlDialect::MySql);
        $mysql = $log->executed(self::UPDATE.' # tag', ['a', 1], 1);
        $log->speaks(SqlDialect::Sqlite);
        $sqlite = $log->executed(self::UPDATE.' # tag', ['a', 1], 1);
        self::assertNotNull($mysql);
        self::assertNotNull($sqlite);

        self::assertSame([self::UPDATE.'  ', self::UPDATE.' # tag'], [$log->statement($mysql)['read'] ?? null, $log->statement($sqlite)['read'] ?? null]);

        // And the middleware tells it by the connection's platform.
        $connection = DriverManager::getConnection(TestConnection::params());
        $driver = $connection->getDriver();
        $expected = SqlDialect::ofPlatform($connection->getDatabasePlatform());
        self::assertSame($expected, ObservingMiddleware::dialectOf($driver, $driver->connect(TestConnection::params())));
    }
}
