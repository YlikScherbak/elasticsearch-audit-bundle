<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;

/**
 * An UPDATE by key that the database says reached no row changed nothing, whatever the listener
 * remembers of the row: there is no history of it.
 *
 * ORM 2.19 keeps an entity it has just removed managed, and a later flush writes its changes: an
 * UPDATE of a row the DELETE took, which reaches nothing -- and the listener, which remembers the
 * row from Doctrine's own data at the next preFlush, wrote a change nobody's row ever had (seeds 3
 * and 37 of the model test, in the ORM 2.19 image). Here the row goes behind the connection's back,
 * so every ORM writes the same UPDATE of nothing.
 */
final class AnUpdateThatReachedNoRowTest extends DoctrineTestCase
{
    public function testAnUpdateThatReachedNoRowIsNoChange(): void
    {
        $article = new Article('One');
        $this->em->persist($article);
        $this->em->flush();
        $written = \count($this->documents());

        // The row taken past the connection's log: Doctrine still holds the article.
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->exec('DELETE FROM Article WHERE id = '.(int) $article->id);

        $article->title = 'Two';
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM Article'), 'the premise: there is no row to have changed');
        self::assertSame([], \array_slice($this->documents(), $written), 'no history of a change no row had');
    }

    public function testAnUpdateThatReachedTheRowIsAChange(): void
    {
        // The mirror: the same UPDATE, the row there.
        $article = new Article('One');
        $this->em->persist($article);
        $this->em->flush();
        $written = \count($this->documents());

        $article->title = 'Two';
        $this->em->flush();

        $said = \array_slice($this->documents(), $written);
        self::assertCount(1, $said);
        self::assertSame(['old' => 'One', 'new' => 'Two'], $said[0]['changes']['title'] ?? null);
    }
}
