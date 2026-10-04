<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowIdentity;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CompositeLine;

/**
 * The entity a foreign key names: the one the manager holds, whether or not a reference is asked
 * for where there is none; and only by a key of one column, as the replay follows one.
 */
final class WhichEntityAForeignKeyNamesTest extends DoctrineTestCase
{
    public function testTheEntityTheManagerHoldsIsTheOneNamed(): void
    {
        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $identity = self::identity();

        self::assertSame([$article, $article], [
            $identity->byForeignKey($this->em, Article::class, $article->id, false),
            $identity->byForeignKey($this->em, Article::class, $article->id, true),
        ]);
    }

    public function testOneTheManagerDoesNotHoldIsAReferenceOnlyWhenAskedFor(): void
    {
        $identity = self::identity();

        self::assertNull($identity->byForeignKey($this->em, Article::class, 999, false));
        self::assertInstanceOf(Article::class, $identity->byForeignKey($this->em, Article::class, 999, true));
        self::assertNull($identity->byForeignKey($this->em, Article::class, null, true), 'and nothing for no key');
    }

    public function testAKeyOfTwoColumnsIsNotFollowedByOne(): void
    {
        self::assertNull(self::identity()->byForeignKey($this->em, CompositeLine::class, 'O-1', true));
    }

    private static function identity(): RowIdentity
    {
        return new RowIdentity(static fn (): int|string|null => null, static fn (): int|string|null => null);
    }
}
