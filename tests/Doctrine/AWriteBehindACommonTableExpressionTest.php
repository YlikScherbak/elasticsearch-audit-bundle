<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;

/**
 * A statement the history cannot replay is doubt where it writes a watched table -- a write
 * behind a common table expression too, which begins with WITH and not with the write. Told by
 * its words outside literals and quoted names: a DELETE in a value, or a column named "delete",
 * is no write. Nothing is made of what such a statement did; it is only said that the history
 * may be missing it.
 */
final class AWriteBehindACommonTableExpressionTest extends DoctrineTestCase
{
    public function testAWriteBehindACommonTableExpressionIsDoubtOfItsTable(): void
    {
        $this->em->persist($article = new Article('One'));
        $this->em->persist($other = new Article('Other'));
        $this->em->flush();
        $this->unownedStatementsAreExpected = true;

        $this->em->getConnection()->beginTransaction();
        // The key written in, not bound: a placeholder's type is not inferred alike everywhere.
        $this->em->getConnection()->executeStatement(sprintf('WITH gone AS (SELECT %d AS id) DELETE FROM Article WHERE id IN (SELECT id FROM gone)', $other->id));
        $article->title = 'One, again';
        $this->em->flush();
        $this->em->getConnection()->commit();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Article WHERE id = ?', [$other->id]), 'the premise: the row went');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of '.Article::class.' since the history was last written, so the history may be missing what they did.'], $this->logs);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readsThatNameAWrite(): iterable
    {
        yield 'a DELETE in a value' => ["WITH said AS (SELECT 'DELETE FROM Article' AS t) SELECT t FROM said"];
        yield 'a column named by a write' => ['WITH said AS (SELECT 1 AS "DELETE") SELECT * FROM said'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readsThatNameAWrite')]
    public function testACommonTableExpressionThatOnlyReadsIsNoDoubt(string $sql): void
    {
        $this->em->persist($article = new Article('One'));
        $this->em->flush();

        $this->em->getConnection()->beginTransaction();
        $this->em->getConnection()->fetchAllAssociative($sql);
        $article->title = 'One, again';
        $this->em->flush();
        $this->em->getConnection()->commit();

        self::assertSame([], $this->logs);
    }

    public function testAWriteBehindACommonTableExpressionOfATableNobodyAuditsIsNoDoubt(): void
    {
        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE TABLE scratch (id INTEGER)');

        $connection->beginTransaction();
        // A DELETE: MySQL takes WITH before a SELECT, an UPDATE or a DELETE, not before an INSERT.
        $connection->executeStatement('WITH one AS (SELECT 1 AS id) DELETE FROM scratch WHERE id IN (SELECT id FROM one)');
        $article->title = 'One, again';
        $this->em->flush();
        $connection->commit();

        self::assertSame([], $this->logs);
    }
}
