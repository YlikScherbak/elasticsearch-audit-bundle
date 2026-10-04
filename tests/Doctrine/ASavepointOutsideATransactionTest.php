<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;

/**
 * A savepoint the application opens with no transaction around it: SQLite begins one, and a
 * RELEASE of it commits it (PostgreSQL refuses the SAVEPOINT; MySQL keeps nothing of it in
 * autocommit). What ran inside is the application's own SQL, said as such, and is what the
 * rows hold from then: the next flush's record starts from it. A ROLLBACK TO it takes it back
 * ({@see \Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation\WhatTheLogKeepsOfFramesAndOwnersTest::testASavepointOpenedOutsideATransactionCanBeRolledBackTo}).
 */
final class ASavepointOutsideATransactionTest extends DoctrineTestCase
{
    public function testWhatRanInsideItIsWhatTheRowsHoldOnceItIsReleased(): void
    {
        if (!TestConnection::isSqlite()) {
            self::markTestSkipped('Only SQLite takes a savepoint outside a transaction.');
        }

        $this->em->persist($article = new Article('One'));
        $this->em->flush();
        $this->gateway->documents = [];
        $this->unownedStatementsAreExpected = true;

        $connection = $this->em->getConnection();
        $connection->executeStatement('SAVEPOINT mine');
        $connection->executeStatement('UPDATE Article SET title = ? WHERE id = ?', ['Raw', $article->id]);
        $connection->executeStatement('RELEASE SAVEPOINT mine');

        self::assertFalse($this->statements->inTransaction(), 'the release ends what the savepoint began');

        $article->title = 'Two';
        $this->em->flush();

        self::assertSame([['old' => 'Raw', 'new' => 'Two']], array_map(static fn (array $d): array => $d['changes']['title'], $this->documents()));
        self::assertSame(['A statement changed title of a '.Article::class.' row, keyed by id, outside every flush, so it is not in the history: SQL the application ran itself, which the bundle does not audit.'], $this->logs);
        $this->logs = [];
    }
}
