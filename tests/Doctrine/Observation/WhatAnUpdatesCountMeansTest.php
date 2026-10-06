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

    /** @return iterable<string, array{0: bool, 1: list<array{string, array<int, mixed>, int|string|null}>, 2: list<string>, 3?: list<string>}> */
    public static function counts(): iterable
    {
        $notKnown = static fn (string $sql): string => 'its count not known: '.$sql;

        foreach (['found' => false, 'changed' => true] as $counted => $changedRows) {
            yield "rows $counted: an UPDATE that reached its row" => [$changedRows, [[self::UPDATE, [1 => 2, 2 => 2], 1]], ['crate C-1 items.2.quantity: 1 -> 2']];
            yield "rows $counted: an UPDATE of a row a DELETE took" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1], [self::UPDATE, [1 => 2, 2 => 2], 0]], ['crate C-1 items.2: "SKU-Y" -> null']];
            yield "rows $counted: an UPDATE of a row a DELETE took, to the value it held" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1], [self::UPDATE, [1 => 1, 2 => 2], 0]], ['crate C-1 items.2: "SKU-Y" -> null']];
            // A count not known -- none given, or one past what an int holds -- is no count of
            // none: the statement is taken as it was written, and said to be doubt. What a DELETE
            // took stays taken.
            yield "rows $counted: an UPDATE with no count, of a row a DELETE took" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1], [self::UPDATE, [1 => 2, 2 => 2], null]], ['crate C-1 items.2: "SKU-Y" -> null'], [$notKnown(self::UPDATE)]];
            yield "rows $counted: an UPDATE with no count" => [$changedRows, [[self::UPDATE, [1 => 2, 2 => 2], null]], ['crate C-1 items.2.quantity: 1 -> 2'], [$notKnown(self::UPDATE)]];
            yield "rows $counted: an UPDATE with a count past an int" => [$changedRows, [[self::UPDATE, [1 => 2, 2 => 2], '9223372036854775808']], ['crate C-1 items.2.quantity: 1 -> 2'], [$notKnown(self::UPDATE)]];
            yield "rows $counted: a DELETE with a count past an int" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], '9223372036854775808']], ['crate C-1 items.2: "SKU-Y" -> null'], [$notKnown('DELETE FROM CrateItem WHERE id = ?')]];
            yield "rows $counted: a DELETE with no count" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], null]], ['crate C-1 items.2: "SKU-Y" -> null'], [$notKnown('DELETE FROM CrateItem WHERE id = ?')]];
            yield "rows $counted: a DELETE with the most an int holds, as a string" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], (string) \PHP_INT_MAX]], ['crate C-1 items.2: "SKU-Y" -> null']];
            yield "rows $counted: a DELETE that took none, as a string" => [$changedRows, [['DELETE FROM CrateItem WHERE id = ?', [1 => 2], '0']], []];
            // An emptying: what was known to be there is what went, and the count not known is not
            // held against it.
            yield "rows $counted: an emptying with a count past an int" => [$changedRows, [['DELETE FROM CrateItem WHERE crate_id = ?', [1 => 'C-1'], '9223372036854775808']], ['crate C-1 items: ["SKU-Y"] -> []'], [$notKnown('DELETE FROM CrateItem WHERE crate_id = ?')]];
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
     * @param list<array{string, array<int, mixed>, int|string|null}> $statements
     * @param list<string>                                       $facts
     * @param list<string>                                       $unsure
     */
    #[DataProvider('counts')]
    public function testWhatAnUpdateReachedIsReadByWhatTheDatabaseCounts(bool $changedRows, array $statements, array $facts, array $unsure = []): void
    {
        $log = new StatementLog(false);
        $log->began();

        foreach ($statements as [$sql, $params, $affected]) {
            $log->executed($sql, $params, $affected);
        }

        $log->committed();
        $replayed = ShadowHistory::fromWhatWasRemembered($this->em, self::ROWS, [], $changedRows)->replay($log, 0);

        self::assertSame(['facts' => $facts, 'unsure' => $unsure], ['facts' => $replayed['facts'], 'unsure' => $replayed['unsure']]);
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
