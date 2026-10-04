<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\HistoryReplay;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the count an UPDATE returns says, by what the database counts: the rows it found
 * (SQLite, PostgreSQL) or the rows it changed (MySQL, MariaDB). Where it counts what it found,
 * none means no row was there. Where it counts what it changed, none may be a row that took no
 * new value -- and where what is remembered says the row would have moved, the database is
 * believed: the row is as it was.
 *
 * Told to the replay here, so that the rule runs where the tests do; which databases count
 * which way is the platform's ({@see testWhatADatabaseCountsIsToldByItsPlatform()}).
 */
final class WhatAnUpdatesCountMeansTest extends DoctrineTestCase
{
    private const ROWS = [
        'Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate' => ['C-1' => ['code' => 'C-1', 'status' => 'packed', 'internalNote' => '']],
        'Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem' => [
            '2' => ['id' => 2, 'sku' => 'SKU-Y', 'quantity' => 1, 'crate_id' => 'C-1'],
        ],
    ];

    private const UPDATE = 'UPDATE CrateItem SET quantity = ? WHERE id = ?';

    /** @return iterable<string, array{bool, list<array{string, array<int, mixed>, int|null}>, list<string>}> */
    public static function counts(): iterable
    {
        foreach (['found' => false, 'changed' => true] as $counted => $changedRows) {
            yield "rows $counted: an UPDATE that reached its row" => [$changedRows, [[self::UPDATE, [1 => 2, 2 => 2], 1]], ['crate C-1 items.2.quantity: 1 -> 2']];
            yield "rows $counted: an UPDATE of a row a DELETE took" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1], [self::UPDATE, [1 => 2, 2 => 2], 0]], ['crate C-1 items.2: "SKU-Y" -> null']];
            yield "rows $counted: an UPDATE of a row a DELETE took, to the value it held" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1], [self::UPDATE, [1 => 1, 2 => 2], 0]], ['crate C-1 items.2: "SKU-Y" -> null']];
            // A count the driver did not give says nothing: what a DELETE took stays taken.
            yield "rows $counted: an UPDATE with no count, of a row a DELETE took" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1], [self::UPDATE, [1 => 2, 2 => 2], null]], ['crate C-1 items.2: "SKU-Y" -> null']];
            yield "rows $counted: an UPDATE with no count" => [$changedRows, [[self::UPDATE, [1 => 2, 2 => 2], null]], ['crate C-1 items.2.quantity: 1 -> 2']];
            yield "rows $counted: an UPDATE of the value the row held already" => [$changedRows, [[self::UPDATE, [1 => 1, 2 => 2], 0], [self::UPDATE, [1 => 3, 2 => 2], 1]], ['crate C-1 items.2.quantity: 1 -> 3']];
        }

        // None counted for a new value: where the database counts what it found, no row was there;
        // where it counts what it changed, the row took no new value -- either way the row is as
        // it was, and what runs after it starts from there.
        yield 'rows found: none counted for a new value' => [false, [[self::UPDATE, [1 => 2, 2 => 2], 0], [self::UPDATE, [1 => 3, 2 => 2], 1]], ['crate C-1 items.2.quantity: 1 -> 3']];
        yield 'rows changed: none counted for a new value' => [true, [[self::UPDATE, [1 => 2, 2 => 2], 0], [self::UPDATE, [1 => 3, 2 => 2], 1]], ['crate C-1 items.2.quantity: 1 -> 3']];
    }

    public function testAnUpdateThatCountedNoneOfARowNothingRememberedIsDoubtOnlyWhereNoneMayBeARowThatWasThere(): void
    {
        // Found none: there was no row. Changed none: there may have been one, unchanged -- and
        // nothing says what it held.
        $said = [];

        foreach ([false, true] as $changedRows) {
            $log = new StatementLog(false);
            $log->began();
            $log->executed(self::UPDATE, [1 => 2, 2 => 7], 0);
            $log->committed();
            $said[] = ShadowHistory::fromWhatWasRemembered($this->em, self::ROWS, [], $changedRows)->replay($log, 0)['unsure'];
        }

        self::assertSame([[], ['an UPDATE of Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem 7, a row nothing said was there']], $said);
    }

    /**
     * @param list<array{string, array<int, mixed>, int|null}> $statements
     * @param list<string>                                $facts
     */
    #[DataProvider('counts')]
    public function testWhatAnUpdateReachedIsReadByWhatTheDatabaseCounts(bool $changedRows, array $statements, array $facts): void
    {
        $log = new StatementLog(false);
        $log->began();

        foreach ($statements as [$sql, $params, $affected]) {
            $log->executed($sql, $params, $affected);
        }

        $log->committed();
        $replayed = ShadowHistory::fromWhatWasRemembered($this->em, self::ROWS, [], $changedRows)->replay($log, 0);

        self::assertSame(['facts' => $facts, 'unsure' => []], ['facts' => $replayed['facts'], 'unsure' => $replayed['unsure']]);
    }

    public function testWhatADatabaseCountsIsToldByItsPlatform(): void
    {
        // A connection never opened: the platform is the one its server version names.
        $config = $this->em->getConfiguration();
        $mysql = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0.31'], $config), $config);
        $postgres = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '16.0'], $config), $config);
        $sqlite = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        $counts = static fn (EntityManager $em): bool => (new \ReflectionMethod(HistoryReplay::class, 'countsTheRowsItChanged'))->invoke(new HistoryReplay($em, []));

        self::assertSame([true, false, false], [$counts($mysql), $counts($postgres), $counts($sqlite)]);
        self::assertFalse((new \ReflectionMethod(HistoryReplay::class, 'countsTheRowsItChanged'))->invoke(new HistoryReplay($mysql, [], countsChangedRows: false)), 'and what the replay is told stands');
    }
}
