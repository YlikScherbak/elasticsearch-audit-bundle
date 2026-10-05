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
