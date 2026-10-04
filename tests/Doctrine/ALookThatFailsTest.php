<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;

/**
 * The look right after a held target's DELETE ({@see \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LookRightAfter})
 * refused by the database: the application's transaction is given back as the look found it --
 * rolled back to the look's own savepoint, which is then let go -- and goes on to commit; what
 * the look would have told is doubt, said in the log.
 */
final class ALookThatFailsTest extends DoctrineTestCase
{
    protected function middlewaresOfTheTest(): array
    {
        // The look alone: who held the tag is asked as 'SELECT ... j WHERE EXISTS', and an
        // article's list as 'SELECT tag_id ...'.
        return [new RefusingMiddleware('SELECT article_id FROM article_tag WHERE')];
    }

    public function testTheTransactionIsGivenBackAndGoesOn(): void
    {
        $this->em->persist($held = new Tag('held'));
        $this->em->persist($article = new Article('One'));
        $article->tags->add($held);
        $this->em->flush();
        $this->gateway->documents = [];
        $this->unownedStatementsAreExpected = true;

        $this->queries = [];
        $this->em->remove($held);
        $article->title = 'One, again';
        $this->em->flush();

        self::assertSame(
            ['SAVEPOINT borsche_audit_look', 'ROLLBACK TO SAVEPOINT borsche_audit_look', 'RELEASE SAVEPOINT borsche_audit_look'],
            array_values(array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, 'borsche_audit_look'))),
        );
        self::assertSame(['One, again', 0], [
            $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]),
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Tag'),
        ], 'the flush committed');
        self::assertSame(['What the connection ran could not be followed for 1 statement(s) of '.Article::class.' since the history was last written, so the history may be missing what they did.'], $this->logs);
    }
}
