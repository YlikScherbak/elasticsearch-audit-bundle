<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use PHPUnit\Framework\TestCase;

/**
 * SQL as an application writes it, not as DBAL does: in lower case, and statements that only
 * look like the ones the log reads a meaning into. DBAL's own are upper case and bare; the log
 * sees every statement the connection runs, the application's among them.
 */
final class WhatTheLogReadsOfSqlAsWrittenTest extends TestCase
{
    public function testSavepointsInLowerCaseAreSavepoints(): void
    {
        $log = new StatementLog();
        $log->began();
        $log->executed('savepoint mine', [], 0);
        $dead = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['a', 1], 1);
        $log->executed('rollback to savepoint mine', [], 0);
        $log->executed('savepoint other', [], 0);
        $kept = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['b', 1], 1);
        $log->executed('release savepoint other', [], 0);
        $log->committed();

        self::assertNotNull($dead);
        self::assertNotNull($kept);
        self::assertSame([StatementLog::VOID, StatementLog::COMMITTED], [$log->fate($dead), $log->fate($kept)]);
    }

    /** @return iterable<string, array{string}> */
    public static function lookAlikes(): iterable
    {
        yield 'a savepoint with something after its name' => ['SAVEPOINT mine AND MORE'];
        yield 'a savepoint after something else' => ['SET x; SAVEPOINT mine'];
        yield 'a release with something after its name' => ['RELEASE SAVEPOINT mine AND MORE'];
        yield 'a release after something else' => ['SET x; RELEASE SAVEPOINT mine'];
        yield 'a rollback to with something after its name' => ['ROLLBACK TO SAVEPOINT mine AND MORE'];
        yield 'a rollback to after something else' => ['SET x; ROLLBACK TO SAVEPOINT mine'];
    }

    /**
     * Only the whole statement is a savepoint's: anything else is a statement like any other,
     * with a place in the log.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lookAlikes')]
    public function testAStatementThatOnlyLooksLikeASavepointIsAStatement(string $sql): void
    {
        $log = new StatementLog();
        $log->began();
        $log->executed('SAVEPOINT mine', [], 0);

        self::assertNotNull($log->executed($sql, [], 0));
    }

    public function testAKeyIsHandedOutForAnInsertInLowerCaseToo(): void
    {
        $log = new StatementLog();
        $at = $log->executed('insert into Article (title) values (?)', ['a'], 1);
        $log->keyHandedOut(7);

        self::assertNotNull($at);
        self::assertSame(7, $log->statement($at)['key'] ?? null);
    }

    public function testAKeyIsNotTakenForAStatementThatOnlyMentionsAnInsert(): void
    {
        $log = new StatementLog();
        $at = $log->executed('/* INSERT */ UPDATE Article SET title = ? WHERE id = ?', ['a', 1], 1);
        $log->keyHandedOut(7);

        self::assertNotNull($at);
        self::assertNull($log->statement($at)['key'] ?? null);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function reads(): iterable
    {
        yield 'a select in lower case' => ['select 1', true];
        yield 'a pragma' => ['PRAGMA foreign_keys = ON', true];
        yield 'an update that mentions a select' => ['UPDATE Article SET title = (SELECT 1) WHERE id = 1', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reads')]
    public function testOnlyAStatementThatBeginsWithAReadIsOne(string $sql, bool $reads): void
    {
        self::assertSame($reads, (new StatementLog())->onlyReads($sql));
    }

    public function testADeleteInLowerCaseIsLookedAfter(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], ['5' => true], 'SELECT article_id FROM article_tag WHERE tag_id = ?');

        self::assertCount(1, $log->toObserveAfter('delete from Tag where id = ?', [1 => 5], 1));
    }

    public function testAStatementThatOnlyMentionsADeleteIsNotLookedAfter(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], ['5' => true], 'SELECT article_id FROM article_tag WHERE tag_id = ?');

        self::assertSame([], $log->toObserveAfter('/* DELETE */ UPDATE Tag SET label = ? WHERE id = ?', [1 => 'x', 2 => 5], 1));
        self::assertSame([], $log->toObserveAfter("UPDATE Tag SET label = 'DELETE' WHERE id = ?", [1 => 5], 1));
    }

    public function testAReleaseInLowerCaseClosesItsFrame(): void
    {
        // What runs after it is the enclosing frame's again.
        $log = new StatementLog();
        $log->began();
        $before = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['a', 1], 1);
        $log->executed('SAVEPOINT mine', [], 0);
        $log->executed('release savepoint mine', [], 0);
        $after = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['b', 1], 1);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame($log->frameOf($before), $log->frameOf($after));
    }

    public function testAJoinRowInsertedInLowerCaseForAKeyAboutToGoIsLookedAtWhenItGoes(): void
    {
        // A tag about to go that nobody held when the flush began, given a link in the flush by
        // the application's own SQL: the link is watched like one Doctrine wrote.
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], [], 'SELECT article_id FROM article_tag WHERE tag_id = ?', ['9' => true], ['table' => 'article_tag', 'columns' => ['tag_id']]);
        $log->executed('insert into article_tag (article_id, tag_id) values (?, ?)', [1 => 1, 2 => 9], 1);

        self::assertCount(1, $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 9], 1));
    }

    /** @return iterable<string, array{string, array<int, mixed>}> */
    public static function writesThatAreNoJoinRowOfTheWatch(): iterable
    {
        yield 'an UPDATE of the join table, with INSERT in a value' => ["UPDATE article_tag SET tag_id = ?, note = 'INSERT' WHERE article_id = ?", [1 => 9, 2 => 1]];
        yield 'an INSERT into another join table with the same column' => ['INSERT INTO shelf_label (shelf_id, tag_id) VALUES (?, ?)', [1 => 1, 2 => 9]];
    }

    /**
     * @param array<int, mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writesThatAreNoJoinRowOfTheWatch')]
    public function testOnlyAnInsertIntoTheWatchedJoinTableIsALinkToWatch(string $sql, array $params): void
    {
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], [], 'SELECT article_id FROM article_tag WHERE tag_id = ?', ['9' => true], ['table' => 'article_tag', 'columns' => ['tag_id']]);
        $log->executed($sql, $params, 1);

        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 9], 1));
    }
}
