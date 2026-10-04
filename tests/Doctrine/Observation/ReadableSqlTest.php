<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ReadableSql;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\SqlDialect;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A statement's comments taken out by its dialect's rules, and what is left in doubt instead:
 * a row for each dialect where they differ.
 */
final class ReadableSqlTest extends TestCase
{
    private const UPDATE = 'UPDATE Article SET title = ? WHERE id = ?';

    /**
     * @return iterable<string, array{string, SqlDialect, string}>
     */
    public static function read(): iterable
    {
        foreach (SqlDialect::cases() as $dialect) {
            $name = $dialect->name;

            yield "$name: nothing to take out" => [self::UPDATE, $dialect, self::UPDATE];
            yield "$name: a tagger's comment after" => [self::UPDATE." /*traceparent='00-ab-cd-01'*/", $dialect, self::UPDATE.'  '];
            yield "$name: a tagger's comment before" => ['/* app:checkout */ '.self::UPDATE, $dialect, '  '.self::UPDATE];
            yield "$name: a comment inside" => ['UPDATE Article SET/* why */title = ? WHERE id = ?', $dialect, 'UPDATE Article SET title = ? WHERE id = ?'];
            yield "$name: a line comment to its end" => ["UPDATE Article SET title = ? -- why\nWHERE id = ?", $dialect, "UPDATE Article SET title = ?  \nWHERE id = ?"];
            yield "$name: what looks like a comment in a literal" => ["UPDATE Article SET title = '/* not a comment */ -- nor this' WHERE id = ?", $dialect, "UPDATE Article SET title = '/* not a comment */ -- nor this' WHERE id = ?"];
            yield "$name: a quote doubled in a literal, then a comment" => ["UPDATE Article SET title = 'it''s -- here' WHERE id = ? -- why", $dialect, "UPDATE Article SET title = 'it''s -- here' WHERE id = ?  "];
            yield "$name: what looks like a comment in a quoted name" => ['UPDATE "Art/*icle" SET title = ? WHERE id = ?', $dialect, 'UPDATE "Art/*icle" SET title = ? WHERE id = ?'];
            yield "$name: a division and a subtraction" => ['UPDATE Article SET rank = rank / 2 - 1 WHERE id = ?', $dialect, 'UPDATE Article SET rank = rank / 2 - 1 WHERE id = ?'];
        }

        // Where a literal or a quoted name ends is where reading goes on: a comment right after
        // one, and an empty one.
        foreach (SqlDialect::cases() as $dialect) {
            $name = $dialect->name;

            yield "$name: a comment right after a literal" => ["UPDATE Article SET title = 'x'/* why */ WHERE id = ?", $dialect, "UPDATE Article SET title = 'x'  WHERE id = ?"];
            yield "$name: a comment right after an empty literal" => ["UPDATE Article SET title = ''/* why */ WHERE id = ?", $dialect, "UPDATE Article SET title = ''  WHERE id = ?"];
            yield "$name: a comment right after a quoted name" => ['UPDATE "Article"/* why */ SET title = ? WHERE id = ?', $dialect, 'UPDATE "Article"  SET title = ? WHERE id = ?'];
            yield "$name: a comment right after an empty quoted name" => ['UPDATE ""/* why */ SET title = ? WHERE id = ?', $dialect, 'UPDATE ""  SET title = ? WHERE id = ?'];
        }

        // `#` is MySQL's comment, and only MySQL's.
        yield 'MySql: a hash comment' => ['UPDATE Article SET title = ? WHERE id = ? # why', SqlDialect::MySql, 'UPDATE Article SET title = ? WHERE id = ?  '];
        yield 'PostgreSql: a hash is no comment' => ['UPDATE Article SET bits = bits # ? WHERE id = ?', SqlDialect::PostgreSql, 'UPDATE Article SET bits = bits # ? WHERE id = ?'];
        yield 'Sqlite: a hash is no comment' => ['UPDATE Article SET title = ? WHERE id = ? # why', SqlDialect::Sqlite, 'UPDATE Article SET title = ? WHERE id = ? # why'];

        // MySQL's `--` begins a comment only before a space: `1--1` is one minus another.
        yield 'MySql: a double minus that is arithmetic' => ['UPDATE Article SET rank = 1--1 WHERE id = ?', SqlDialect::MySql, 'UPDATE Article SET rank = 1--1 WHERE id = ?'];
        yield 'MySql: a double minus at the end' => ['UPDATE Article SET title = ? WHERE id = ? --', SqlDialect::MySql, 'UPDATE Article SET title = ? WHERE id = ?  '];
        yield 'MySql: a backtick name' => ['UPDATE `Art--icle` SET title = ? WHERE id = ?', SqlDialect::MySql, 'UPDATE `Art--icle` SET title = ? WHERE id = ?'];

        // PostgreSQL's block comments nest; its dollar quotes and E-strings are literals.
        yield 'PostgreSql: a nested comment' => ['UPDATE Article /* a /* b */ c */ SET title = ? WHERE id = ?', SqlDialect::PostgreSql, 'UPDATE Article   SET title = ? WHERE id = ?'];
        yield 'MySql: a comment does not nest' => ['UPDATE Article /* a /* b */ SET title = ? WHERE id = ?', SqlDialect::MySql, 'UPDATE Article   SET title = ? WHERE id = ?'];
        yield 'PostgreSql: a dollar-quoted string' => ['UPDATE Article SET title = $t$ -- not /* a comment $t$ WHERE id = ?', SqlDialect::PostgreSql, 'UPDATE Article SET title = $t$ -- not /* a comment $t$ WHERE id = ?'];
        yield 'PostgreSql: a dollar in a name is no quote' => ['UPDATE Article SET a$b = 1 WHERE id = ? -- why', SqlDialect::PostgreSql, 'UPDATE Article SET a$b = 1 WHERE id = ?  '];
        yield 'PostgreSql: a dollar quote with no tag' => ['$$ -- x $$ -- why', SqlDialect::PostgreSql, '$$ -- x $$  '];
        yield 'PostgreSql: a dollar quote right after another' => ['$$x$$$$ -- y $$', SqlDialect::PostgreSql, '$$x$$$$ -- y $$'];
        yield 'PostgreSql: dollars inside a name quote nothing' => ['UPDATE Article SET a$b$c = 1 WHERE id = ? -- why', SqlDialect::PostgreSql, 'UPDATE Article SET a$b$c = 1 WHERE id = ?  '];
        yield 'MySql: dollars quote nothing' => ["UPDATE Article SET title = \$\$ -- x \$\$\nWHERE id = ?", SqlDialect::MySql, "UPDATE Article SET title = \$\$  \nWHERE id = ?"];
        yield 'Sqlite: a bracket doubled inside a bracketed name' => ['UPDATE [Art]]--icle] SET title = ? WHERE id = ?', SqlDialect::Sqlite, 'UPDATE [Art]]--icle] SET title = ? WHERE id = ?'];
        yield 'Sqlite: dollars quote nothing' => ['UPDATE Article SET title = $$ /* x */ $$ WHERE id = ?', SqlDialect::Sqlite, 'UPDATE Article SET title = $$   $$ WHERE id = ?'];
        yield 'PostgreSql: a positional parameter is no dollar quote' => ['UPDATE Article SET title = $1 WHERE id = $2 -- why', SqlDialect::PostgreSql, 'UPDATE Article SET title = $1 WHERE id = $2  '];
        yield 'PostgreSql: a backtick is no quote' => ['UPDATE `Art--icle` SET title = ? WHERE id = ?', SqlDialect::PostgreSql, 'UPDATE `Art '];
        yield 'PostgreSql: a bracket is no quote' => ['UPDATE Article SET tags[1 /* why */] = ? WHERE id = ?', SqlDialect::PostgreSql, 'UPDATE Article SET tags[1  ] = ? WHERE id = ?'];
        yield 'MySql: a bracket is no quote' => ['UPDATE Article SET a = 1 /* [ */ WHERE id = ? /* ] */', SqlDialect::MySql, 'UPDATE Article SET a = 1   WHERE id = ?  '];
        yield 'PostgreSql: a backslash in a quoted name escapes nothing' => ['UPDATE "Art\\" -- " SET title = ? WHERE id = ?', SqlDialect::PostgreSql, 'UPDATE "Art\\"  '];
        yield 'MySql: a backslash in a double-quoted string, before no quote' => ['UPDATE Article SET title = "a\\b" -- why', SqlDialect::MySql, 'UPDATE Article SET title = "a\\b"  '];
        yield 'PostgreSql: an escape string at the start' => ["E'a\\'b' -- why", SqlDialect::PostgreSql, "E'a\\'b'  "];
        yield 'PostgreSql: an escape string in lower case' => ["UPDATE Article SET title = e'it\\'s' WHERE id = ? -- why", SqlDialect::PostgreSql, "UPDATE Article SET title = e'it\\'s' WHERE id = ?  "];
        yield 'PostgreSql: a doubled quote in an escape string' => ["UPDATE Article SET title = E'a''\\'b' WHERE id = ? -- why", SqlDialect::PostgreSql, "UPDATE Article SET title = E'a''\\'b' WHERE id = ?  "];
        yield 'PostgreSql: an escape string of one character' => ["UPDATE Article SET title = E'\\'' WHERE id = ? -- why", SqlDialect::PostgreSql, "UPDATE Article SET title = E'\\'' WHERE id = ?  "];
        yield 'PostgreSql: an escape string' => ["UPDATE Article SET title = E'it\\'s -- here' WHERE id = ? -- why", SqlDialect::PostgreSql, "UPDATE Article SET title = E'it\\'s -- here' WHERE id = ?  "];

        // A backslash is a backslash where no dialect makes it an escape.
        yield 'Sqlite: a backslash before a quote ends nothing' => ["UPDATE Article SET title = 'a\\' WHERE id = ? -- why", SqlDialect::Sqlite, "UPDATE Article SET title = 'a\\' WHERE id = ?  "];
        yield 'Sqlite: a bracketed name' => ['UPDATE [Art--icle] SET title = ? WHERE id = ?', SqlDialect::Sqlite, 'UPDATE [Art--icle] SET title = ? WHERE id = ?'];
    }

    #[DataProvider('read')]
    public function testACommentIsTakenOutByItsDialectsRules(string $sql, SqlDialect $dialect, string $read): void
    {
        $readable = ReadableSql::of($sql, $dialect);

        self::assertSame([$read, null], [$readable->text, $readable->unreadable]);
    }

    /**
     * @return iterable<string, array{string, SqlDialect, string}>
     */
    public static function unreadable(): iterable
    {
        foreach (SqlDialect::cases() as $dialect) {
            $name = $dialect->name;

            yield "$name: an executable comment" => ['/*!40101 SET NAMES utf8 */ '.self::UPDATE, $dialect, 'a comment the server reads: executable'];
            yield "$name: a hint" => ['UPDATE /*+ INDEX(a) */ Article SET title = ? WHERE id = ?', $dialect, 'a comment the server reads: a hint'];
            yield "$name: a comment that does not end" => [self::UPDATE.' /* why', $dialect, 'a comment that does not end'];
            yield "$name: a literal that does not end" => ["UPDATE Article SET title = 'x WHERE id = ? -- why", $dialect, 'a string literal that does not end'];
            yield "$name: a quoted name that does not end" => ['UPDATE "Article SET title = ? -- why', $dialect, 'a quoted name that does not end'];
        }

        // Where a server mode decides whether a backslash escapes the quote after it.
        yield 'MySql: a backslash before a quote' => ["UPDATE Article SET title = 'a\\' -- ' WHERE id = ?", SqlDialect::MySql, 'a backslash before a quote in a string literal, whose end depends on the server\'s mode'];
        yield 'MySql: a backslash before a double quote' => ['UPDATE Article SET title = "a\\" -- " WHERE id = ?', SqlDialect::MySql, 'a backslash before a quote in a double-quoted string, whose end depends on the server\'s mode'];
        yield 'PostgreSql: a backslash before a quote outside an escape string' => ["UPDATE Article SET title = 'a\\' -- ' WHERE id = ?", SqlDialect::PostgreSql, 'a backslash before a quote in a string literal, whose end depends on the server\'s mode'];
        yield 'PostgreSql: an E ending a name begins no escape string' => ["UPDATE Article SET title = xE'a\\' -- ' WHERE id = ?", SqlDialect::PostgreSql, 'a backslash before a quote in a string literal, whose end depends on the server\'s mode'];
        yield 'PostgreSql: a literal first, an E last' => ["'a\\' x' -- E", SqlDialect::PostgreSql, 'a backslash before a quote in a string literal, whose end depends on the server\'s mode'];
        yield 'PostgreSql: a comment that does not end, ending in a star' => ['UPDATE Article SET title = ? /* why *', SqlDialect::PostgreSql, 'a comment that does not end'];
        yield 'PostgreSql: a dollar-quoted string that does not end' => ['UPDATE Article SET title = $t$ x -- why', SqlDialect::PostgreSql, 'a dollar-quoted string that does not end'];
    }

    #[DataProvider('unreadable')]
    public function testWhatTheRulesLeaveInDoubtIsNotRead(string $sql, SqlDialect $dialect, string $why): void
    {
        $readable = ReadableSql::of($sql, $dialect);

        self::assertSame([null, $why], [$readable->text, $readable->unreadable]);
    }

    public function testTheSameTextReadsByEachDialectsOwnRules(): void
    {
        // One text, two connections: a cache by the text alone would hand one's reading to the other.
        $sql = 'UPDATE Article SET title = ? WHERE id = ? # why';

        self::assertSame('UPDATE Article SET title = ? WHERE id = ?  ', ReadableSql::of($sql, SqlDialect::MySql)->text);
        self::assertSame($sql, ReadableSql::of($sql, SqlDialect::PostgreSql)->text);
        self::assertSame('UPDATE Article SET title = ? WHERE id = ?  ', ReadableSql::of($sql, SqlDialect::MySql)->text, 'and the first again');
    }

    public function testTheDialectIsThePlatforms(): void
    {
        self::assertSame(
            [SqlDialect::MySql, SqlDialect::PostgreSql, SqlDialect::Other],
            [SqlDialect::ofPlatform(new MySQLPlatform()), SqlDialect::ofPlatform(new PostgreSQLPlatform()), SqlDialect::ofPlatform(new SQLServerPlatform())],
        );
        $sqlite = class_exists('Doctrine\DBAL\Platforms\SQLitePlatform') ? 'Doctrine\DBAL\Platforms\SQLitePlatform' : 'Doctrine\DBAL\Platforms\SqlitePlatform';
        self::assertSame(SqlDialect::Sqlite, SqlDialect::ofPlatform(new $sqlite()));
    }
}
