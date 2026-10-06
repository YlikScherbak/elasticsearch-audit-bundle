<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The application's write of a watched table named otherwise than its mapping -- in lower or upper
 * case, which PostgreSQL folds and SQLite ignores, or behind a schema -- is doubt of a table named
 * like the watched one's: a warning that the history may be missing it, never a record made of it.
 * Said as a table *named like* it, because it may be another one: of another schema, or of another
 * case where the database keeps the case.
 */
final class AWriteOfATableNamedOtherwiseTest extends DoctrineTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function names(): iterable
    {
        yield 'in lower case, read' => ["UPDATE article SET title = 'Replaced' WHERE id = %d"];
        yield 'in upper case, read' => ["UPDATE ARTICLE SET title = 'Replaced' WHERE id = %d"];
        yield 'in lower case, not read' => ["WITH x AS (SELECT %d AS id) UPDATE article SET title = 'Replaced' WHERE id IN (SELECT id FROM x)"];
        yield 'behind its schema' => ["UPDATE %%SCHEMA%%.Article SET title = 'Replaced' WHERE id = %d"];
    }

    #[DataProvider('names')]
    public function testAWriteOfATableNamedLikeAWatchedOneIsDoubtAndNoRecord(string $sql): void
    {
        $connection = $this->em->getConnection();
        $schema = TestConnection::isSqlite() ? 'main' : (str_contains($connection->getDatabasePlatform()::class, 'PostgreSQL') ? 'public' : (string) $connection->fetchOne('SELECT DATABASE()'));
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;
        $before = \count($this->documents());

        try {
            $connection->executeStatement(sprintf(str_replace('%%SCHEMA%%', $schema, $sql), $other->id));
        } catch (\Doctrine\DBAL\Exception $e) {
            // MySQL on Linux keeps a table name's case: `article` is no table there.
            self::markTestSkipped('The database refuses the form: '.$e->getMessage());
        }

        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame('Replaced', $connection->fetchOne('SELECT title FROM Article WHERE id = ?', [$other->id]), 'the premise: the row was written');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of a table named like '.Article::class.'\'s since the history was last written, so the history may be missing what they did.'], $this->logs);

        // Nothing is made of it: the one record since is the flush's own, of the other article.
        $since = \array_slice($this->documents(), $before);
        self::assertCount(1, $since);
        self::assertSame([(string) $article->id, ['old' => 'One', 'new' => 'One, again']], [(string) $since[0]['objectId'], $since[0]['changes']['title'] ?? null]);
        self::assertStringNotContainsString('Replaced', (string) json_encode($since));
    }

    public function testAWriteOfAJoinTableNamedLikeAWatchedOnesIsDoubtOfItsOwner(): void
    {
        $connection = $this->em->getConnection();
        $article = new Article('One');
        $article->tags->add($tag = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag('php'));
        $this->em->persist($tag);
        $this->em->persist($article);
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;
        $before = \count($this->documents());

        try {
            $connection->executeStatement(sprintf('DELETE FROM ARTICLE_TAG WHERE article_id = %d', $article->id));
        } catch (\Doctrine\DBAL\Exception $e) {
            self::markTestSkipped('The database refuses the form: '.$e->getMessage());
        }

        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_tag'), 'the premise: the link went');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of a table named like '.Article::class.'\'s since the history was last written, so the history may be missing what they did.'], $this->logs);
        self::assertStringNotContainsString('tags', (string) json_encode(\array_slice($this->documents(), $before)), 'and no record of the link is made');
    }

    public function testTwoTablesNamedLikeWatchedOnesInOneStatementAreOneDoubtOfBoth(): void
    {
        $this->onlyOn('SQLite'); // two statements in one, run as one by the driver
        $connection = $this->em->getConnection();
        $this->em->persist(new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate('C-1'));
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $connection->executeStatement(sprintf("UPDATE article SET title = 'Replaced' WHERE id = %d; UPDATE crate SET status = 'shipped' WHERE code = 'C-1'", $other->id));
        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of a table named like '.Article::class.'\'s, a table named like '.\Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate::class.'\'s since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    public function testTheDoubtOfATableNamedLikeAWatchedOneSaysTheStatementAndWhyItWasNotBound(): void
    {
        // What the replay says of it, beside the warning: the statement, and why it was not bound.
        $log = new \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog();
        $log->executed('UPDATE article SET title = ? WHERE id = ?', [1 => 'Replaced', 2 => 7], 1);
        $replay = new \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\HistoryReplay($this->em, []);
        $replay->replay($log, 0);

        self::assertCount(1, $replay->doubts());
        self::assertStringStartsWith('not bound: UPDATE article SET title = ? WHERE id = ? -- ', $replay->doubts()[0]);
        self::assertGreaterThan(\strlen('not bound: UPDATE article SET title = ? WHERE id = ? -- '), \strlen($replay->doubts()[0]), 'and why');
    }

    private function onlyOn(string $platform): void
    {
        if (!(TestConnection::isSqlite() ? $platform === 'SQLite' : str_contains($this->em->getConnection()->getDatabasePlatform()::class, $platform))) {
            self::markTestSkipped('The form is '.$platform.'\'s.');
        }
    }

    public function testATableNamedLikeOneNobodyAuditsIsNoDoubt(): void
    {
        // Tag is no class the history is written about: its table, in any case, is the
        // application's own.
        $this->em->persist($tag = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag('php'));
        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        try {
            $this->em->getConnection()->executeStatement(sprintf("UPDATE TAG SET label = 'go' WHERE id = %d", $tag->id));
        } catch (\Doctrine\DBAL\Exception $e) {
            self::markTestSkipped('The database refuses the form: '.$e->getMessage());
        }

        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame([], $this->logs);
    }

    public function testAWatchedTableAfterOnesNobodyAuditsIsFoundToo(): void
    {
        // Every watched class is asked, whatever stands before it among the mapped ones.
        $this->em->persist($crate = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate('C-1'));
        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        try {
            $this->em->getConnection()->executeStatement("UPDATE CRATE SET status = 'shipped' WHERE code = 'C-1'");
        } catch (\Doctrine\DBAL\Exception $e) {
            self::markTestSkipped('The database refuses the form: '.$e->getMessage());
        }

        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of a table named like '.\Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate::class.'\'s since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    public function testAWriteOfTheTableAsMappedIsDoubtOfItsClassAndNotOfALikeOne(): void
    {
        // The mapping's own spelling is matched exactly, as before: a value written in, which is
        // not bound, is doubt of the class itself -- not of a table named like its.
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $this->em->getConnection()->executeStatement(sprintf("UPDATE Article SET title = 'Replaced' WHERE id = %d", $other->id));
        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of '.Article::class.' since the history was last written, so the history may be missing what they did.'], $this->logs);
    }
}
