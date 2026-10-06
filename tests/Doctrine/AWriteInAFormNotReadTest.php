<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Comment;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;

/**
 * Writes of a watched table in forms this reader does not read and Doctrine never writes -- the
 * application's own SQL: each is doubt of the table it writes, said at the next flush, and
 * nothing is made of what it did. Each where its database has the form.
 */
final class AWriteInAFormNotReadTest extends DoctrineTestCase
{
    public function testAReplaceIsDoubt(): void
    {
        $this->onlyOn('MySQL', 'SQLite');
        $this->aWriteOfAnArticle("REPLACE INTO Article (id, title, status, views) VALUES (%d, 'Replaced', 'draft', 0)");
    }

    public function testAnInsertOrReplaceIsDoubt(): void
    {
        $this->onlyOn('SQLite');
        $this->aWriteOfAnArticle("INSERT OR REPLACE INTO Article (id, title, status, views) VALUES (%d, 'Replaced', 'draft', 0)");
    }

    public function testAMergeIsDoubt(): void
    {
        $this->onlyOn('PostgreSQL');

        if (version_compare((string) $this->em->getConnection()->fetchOne('SHOW server_version_num'), '150000', '<')) {
            self::markTestSkipped('MERGE is PostgreSQL 15\'s.');
        }

        $this->aWriteOfAnArticle("MERGE INTO Article a USING (SELECT %d AS id) s ON a.id = s.id WHEN MATCHED THEN UPDATE SET title = 'Replaced'");
    }

    public function testATruncateIsDoubt(): void
    {
        $this->onlyOn('MySQL', 'PostgreSQL');
        $this->em->persist(new Comment('c-1', 'Hello'));
        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE Comment');
        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Comment'), 'the premise: the rows went');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of '.Comment::class.' since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    /**
     * MySQL's writes of several tables, an unwatched one first or an alias for the watched one:
     * doubt of any table, which of them each writes being what this does not read.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function writesOfSeveralTables(): iterable
    {
        yield 'an UPDATE with a join' => ["UPDATE scratch s JOIN Article a ON a.id = s.id SET a.title = 'Replaced'", false];
        yield 'an UPDATE of two tables' => ["UPDATE scratch s, Article a SET a.title = 'Replaced' WHERE a.id = s.id", false];
        yield 'a DELETE of names before FROM' => ['DELETE s, a FROM scratch s JOIN Article a ON a.id = s.id', true];
        yield 'a DELETE FROM two names USING' => ['DELETE FROM s, a USING scratch s JOIN Article a ON a.id = s.id', true];
        yield 'a DELETE of an alias' => ['DELETE a FROM Article a JOIN scratch s ON a.id = s.id', true];
        yield 'a DELETE of an alias, with no join' => ['DELETE a FROM Article a WHERE a.id IN (SELECT id FROM scratch)', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writesOfSeveralTables')]
    public function testAWriteOfSeveralTablesIsDoubtOfAnyTable(string $sql, bool $deletes): void
    {
        $this->onlyOn('MySQL');
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS scratch (id INTEGER)');
        $connection->executeStatement('DELETE FROM scratch');
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $connection->executeStatement(sprintf('INSERT INTO scratch (id) VALUES (%d)', $other->id));
        $connection->executeStatement($sql);
        $article->title = 'One, again';
        $this->em->flush();

        if ($deletes) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$other->id]), 'the premise: the row went');
        } else {
            self::assertSame('Replaced', $connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$other->id]), 'the premise: the row was written');
        }

        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of an unknown table since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    /** A table that cannot be named is not taken back by one named after it. */
    public function testATableNotNamedStaysDoubtWhenAnotherIsNamedAfterIt(): void
    {
        $this->onlyOn('MySQL');
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS scratch (id INTEGER)');
        $connection->executeStatement('DELETE FROM scratch');
        $this->em->persist(new Comment('c-1', 'Hello'));
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $connection->executeStatement(sprintf('INSERT INTO scratch (id) VALUES (%d)', $other->id));
        // Two statements in one: the driver runs both, and the log has them as one it cannot read.
        $connection->executeStatement("UPDATE scratch s JOIN Article a ON a.id = s.id SET a.title = 'Replaced'; UPDATE Comment SET body = 'Changed' WHERE id = 'c-1'");
        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame('Replaced', $connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$other->id]), 'the premise: the article was written');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of '.Comment::class.', an unknown table since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    /** One statement writing two watched tables: one doubt, naming both. */
    public function testAWriteOfTwoWatchedTablesIsOneDoubtOfBoth(): void
    {
        $this->onlyOn('PostgreSQL');
        $this->em->persist(new Comment('c-1', 'Hello'));
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $this->em->getConnection()->executeStatement(sprintf("WITH gone AS (DELETE FROM Comment WHERE id = 'c-1' RETURNING id) DELETE FROM Article WHERE id = %d", $other->id));
        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Comment'), 'the premise: the comment went');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$other->id]), 'the premise: the article went');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of '.Comment::class.', '.Article::class.' since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    /** PostgreSQL's DELETE … USING reads other tables and writes the one it names. */
    public function testADeleteUsingOtherTablesIsDoubtOfItsOwn(): void
    {
        $this->onlyOn('PostgreSQL');
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS scratch (id INTEGER)');
        $connection->executeStatement('DELETE FROM scratch');
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $connection->executeStatement(sprintf('INSERT INTO scratch (id) VALUES (%d)', $other->id));
        $connection->executeStatement('DELETE FROM Article a USING scratch s, scratch t WHERE a.id = s.id AND t.id = s.id');
        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$other->id]), 'the premise: the row went');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of '.Article::class.' since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    private function aWriteOfAnArticle(string $sql): void
    {
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $this->em->getConnection()->executeStatement(sprintf($sql, $other->id));
        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame('Replaced', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$other->id]), 'the premise: the row was written');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of '.Article::class.' since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    private function onlyOn(string ...$platforms): void
    {
        $platform = TestConnection::isSqlite() ? 'SQLite' : $this->em->getConnection()->getDatabasePlatform()::class;

        foreach ($platforms as $one) {
            if (str_contains($platform, $one)) {
                return;
            }
        }

        self::markTestSkipped('The form is '.implode(' and ', $platforms).'\'s.');
    }
}
