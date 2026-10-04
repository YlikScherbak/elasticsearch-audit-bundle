<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;

/**
 * A crate's line deleted by the application's own SQL, then changed through Doctrine, which
 * does not know: its UPDATE finds no row. On every database the history says nothing of a
 * line that was not there -- MySQL, which counts the rows an UPDATE changed rather than found,
 * included.
 */
final class AnUpdateOfALineThatWasGoneTest extends DoctrineTestCase
{
    public function testTheLineThatWasNotThereChangesNothing(): void
    {
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($item = new CrateItem('SKU-A'));
        $this->em->flush();
        $this->gateway->documents = [];
        $this->unownedStatementsAreExpected = true;

        $this->em->getConnection()->executeStatement('DELETE FROM CrateItem WHERE id = ?', [$item->id]);
        $item->quantity = 5;
        $crate->status = 'shipped';
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem'), 'the premise: the line is gone');
        self::assertSame(
            [['status']],
            array_map(static fn (array $d): array => array_keys(array_filter($d['changes'], static fn (array $c): bool => $c['old'] !== $c['new'])), $this->documents()),
            'the crate\'s own change, and nothing of a line that was not there',
        );
        $this->logs = [];
    }

    public function testALineAnotherProcessDeletedChangesNothingEither(): void
    {
        // Deleted on a connection of its own, which the log does not hear: only the UPDATE's
        // count says the line was not there.
        if (\Borsche\ElasticsearchAuditBundle\Tests\TestConnection::isSqlite()) {
            self::markTestSkipped('An in-memory SQLite database is one connection\'s own.');
        }

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($item = new CrateItem('SKU-A'));
        $this->em->flush();
        $this->gateway->documents = [];

        $other = \Doctrine\DBAL\DriverManager::getConnection(\Borsche\ElasticsearchAuditBundle\Tests\TestConnection::params());
        $other->executeStatement('DELETE FROM CrateItem WHERE id = ?', [$item->id]);
        $other->close();
        $item->quantity = 5;
        $crate->status = 'shipped';
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem'), 'the premise: the line is gone');
        self::assertSame(
            [['status']],
            array_map(static fn (array $d): array => array_keys(array_filter($d['changes'], static fn (array $c): bool => $c['old'] !== $c['new'])), $this->documents()),
            'the crate\'s own change, and nothing of a line that was not there',
        );
    }
}
