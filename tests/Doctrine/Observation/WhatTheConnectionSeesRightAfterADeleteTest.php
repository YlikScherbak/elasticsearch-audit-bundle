<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;

/**
 * The connection's side of looking right after a DELETE (5.3, the cascade), on a real
 * connection: what is seen is the rows as the DELETE left them, nothing asked is the
 * application's, and asking changes nothing the application sees -- not even when it fails,
 * inside a transaction, on a database where a failed statement spoils the transaction.
 */
final class WhatTheConnectionSeesRightAfterADeleteTest extends DoctrineTestCase
{
    private const READ = 'SELECT article_id FROM article_tag WHERE tag_id = ?';

    public function testWhatIsSeenIsTheRowsAsTheDeleteLeftThemAndNothingAskedIsLogged(): void
    {
        [$tag, $articles] = $this->aTagHeldBy(2);
        $connection = $this->em->getConnection();
        $this->statements->watch(1, 'tags', 'Tag', ['id'], [(string) $tag => true], self::READ);
        $before = $this->statements->position();

        self::assertSame(1, $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$tag]), 'what the application is told is what the driver said');

        $at = $before + 1;
        self::assertSame($at, $this->statements->position(), 'the question asked is not the application\'s statement');
        $left = array_map(static fn (mixed $id): int => (int) $id, $connection->fetchFirstColumn(self::READ, [$tag]));
        $seen = array_map(static fn (array $row): int => (int) $row[0], $this->statements->observationsOf($at)['tags'] ?? [[-1]]);
        sort($left);
        sort($seen);

        // Whatever this database did with the rows -- took them with the tag, or left them --
        // what was seen is it.
        self::assertSame($left, $seen);
        self::assertContains($left, [[], $articles], 'the premise: either all went or none');
    }

    public function testADeleteThatTookNoRowIsNotLookedAt(): void
    {
        [$tag] = $this->aTagHeldBy(1);
        $this->statements->watch(1, 'tags', 'Tag', ['id'], [(string) $tag => true], self::READ);
        $this->em->getConnection()->executeStatement('DELETE FROM Tag WHERE id = ?', [$tag + 1000]);

        self::assertSame([], $this->statements->observationsOf($this->statements->position()));
    }

    public function testInsideATransactionTheLookLeavesItAsItWasAndTheApplicationGoesOn(): void
    {
        [$tag] = $this->aTagHeldBy(1);
        [$other] = $this->aTagHeldBy(1);
        $connection = $this->em->getConnection();
        $this->statements->watch(1, 'tags', 'Tag', ['id'], [(string) $tag => true], self::READ);

        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$tag]);
        self::assertArrayHasKey('tags', $this->statements->observationsOf($this->statements->position()));
        self::assertTrue($this->statements->inTransaction(), 'the look\'s savepoint is no frame of the log\'s');
        $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$other]);
        $connection->commit();

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM Tag WHERE id IN (?, ?)', [$tag, $other]), 'both committed');
        self::assertFalse($this->statements->inTransaction());
    }

    public function testAQuestionThatFailsIsALookNotTakenAndTheTransactionGoesOnAndCommits(): void
    {
        // A question that cannot be asked -- a table that is not there. The DELETE succeeded and
        // the application is told so; what was seen is null, doubt and not nothing; and the
        // transaction is as it was: the next statement runs and the commit commits, on
        // PostgreSQL too, where a failed statement would otherwise have spoiled it.
        [$tag] = $this->aTagHeldBy(1);
        [$other] = $this->aTagHeldBy(1);
        $connection = $this->em->getConnection();
        $this->statements->watch(1, 'tags', 'Tag', ['id'], [(string) $tag => true, (string) $other => true], 'SELECT article_id FROM no_such_table WHERE tag_id = ?');

        $connection->beginTransaction();
        self::assertSame(1, $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$tag]));
        self::assertSame(['tags' => null], $this->statements->observationsOf($this->statements->position()));
        $connection->executeStatement('UPDATE Tag SET label = ? WHERE id = ?', ['still here', $other]);
        $connection->commit();

        self::assertSame([0, 'still here'], [(int) $connection->fetchOne('SELECT COUNT(*) FROM Tag WHERE id = ?', [$tag]), $connection->fetchOne('SELECT label FROM Tag WHERE id = ?', [$other])]);

        // And outside one: the same, with nothing to give back.
        self::assertSame(1, $connection->executeStatement('DELETE FROM Tag WHERE id = ?', [$other]));
        self::assertSame(['tags' => null], $this->statements->observationsOf($this->statements->position()));
    }

    public function testAStatementWithItsValuesInItsTextIsNotLookedAt(): void
    {
        // What the key is, is read from the parameters; a DELETE that carries none says nothing
        // the watch can be matched against, and is left alone -- the history says so as doubt.
        [$tag] = $this->aTagHeldBy(1);
        $this->statements->watch(1, 'tags', 'Tag', ['id'], [(string) $tag => true], self::READ);
        $this->em->getConnection()->executeStatement(sprintf('DELETE FROM Tag WHERE id = %d', $tag));

        self::assertSame([], $this->statements->observationsOf($this->statements->position()));
    }

    /**
     * A tag and the articles holding it, written through the connection.
     *
     * @return array{0: int, 1: list<int>}
     */
    private function aTagHeldBy(int $articles): array
    {
        $this->em->persist($tag = new Tag('t'));
        $held = [];

        for ($i = 0; $i < $articles; ++$i) {
            $article = new Article('A'.$i);
            $article->tags->add($tag);
            $this->em->persist($article);
            $held[] = $article;
        }

        $this->em->flush();
        $ids = array_map(static fn (Article $a): int => (int) $a->id, $held);
        sort($ids);

        return [(int) $tag->id, $ids];
    }
}
