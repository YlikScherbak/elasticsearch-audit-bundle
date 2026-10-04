<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;

/**
 * The shadow history's own rules, on logs fed by hand against the fixtures' mapping.
 *
 * The acceptance tests run it on real flushes; some of what it has to get right does not come
 * out of a flush in any order Doctrine allows -- an UPDATE after a DELETE that was rolled back,
 * a row deleted and inserted again under the key it had -- and those are here.
 */
final class ShadowHistoryTest extends DoctrineTestCase
{
    private const ROWS = [
        'Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate' => ['C-1' => ['code' => 'C-1', 'status' => 'packed', 'internalNote' => '']],
        'Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem' => [
            '1' => ['id' => 1, 'sku' => 'SKU-X', 'quantity' => 1, 'crate_id' => 'C-1'],
            '2' => ['id' => 2, 'sku' => 'SKU-Y', 'quantity' => 1, 'crate_id' => 'C-1'],
            '9' => ['id' => 9, 'sku' => 'SKU-LOOSE', 'quantity' => 1, 'crate_id' => null],
        ],
    ];

    /** Hopper shows a chute by its size, the field that changes. */
    private const CHUTES = [
        'Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Chute' => [
            '1' => ['id' => 1, 'size' => 3, 'hopper_id' => 1, 'inspector_id' => null],
        ],
    ];

    public function testAnUpdateAfterADeleteOfTheSameRowReachedNothing(): void
    {
        $log = $this->begun();
        $log->executed('DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1);
        $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 2, 2 => 2], 0);
        $log->committed();

        self::assertSame(['facts' => ['crate C-1 items.2: "SKU-Y" -> null'], 'unsure' => []], $this->replayed($log));
    }

    public function testAnUpdateAfterADeleteThatWasRolledBackReachedTheRow(): void
    {
        $log = $this->begun();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->executed('DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1);
        $log->executed('ROLLBACK TO SAVEPOINT DOCTRINE_2', [], 0);
        $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 2, 2 => 2], 1);
        $log->committed();

        self::assertSame(['facts' => ['crate C-1 items.2.quantity: 1 -> 2'], 'unsure' => []], $this->replayed($log));
    }

    public function testAnUpdateOfARowInsertedAgainUnderItsKeyReachedTheNewRow(): void
    {
        $log = $this->begun();
        $log->executed('DELETE FROM CrateItem WHERE id = ?', [1 => 2], 1);
        $log->executed('INSERT INTO CrateItem (id, sku, quantity, crate_id) VALUES (?, ?, ?, ?)', [1 => 2, 2 => 'SKU-Z', 3 => 5, 4 => 'C-1'], 1);
        $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 6, 2 => 2], 1);
        $log->committed();

        self::assertSame(['facts' => [
            'crate C-1 items.2: "SKU-Y" -> null',
            'crate C-1 items.2: null -> "SKU-Z"',
            'crate C-1 items.2.quantity: 5 -> 6',
        ], 'unsure' => []], $this->replayed($log));
    }

    public function testADeleteThatTookNoRowIsNotADeparture(): void
    {
        // A DELETE counts what it took on every engine; nothing taken is nothing that left.
        // A flush whose publishing was swallowed leaves its schedule for the next one, which
        // deletes the same row a second time.
        $log = $this->begun();
        $log->executed('DELETE FROM CrateItem WHERE id = ?', [1 => 2], 0);
        $log->committed();

        self::assertSame(['facts' => [], 'unsure' => []], $this->replayed($log));
    }

    public function testARowWhoseKeyTheDatabaseHandedOutHasTheKeyItsOwnInsertWasAnswered(): void
    {
        // Answered out of order on purpose: the key is the one given right after the INSERT, not
        // the nth of anything.
        $log = $this->begun();
        $log->executed('INSERT INTO CrateItem (sku, quantity, crate_id) VALUES (?, ?, ?)', [1 => 'SKU-P', 2 => 1, 3 => 'C-1'], 1);
        $log->keyHandedOut('4');
        $log->executed('INSERT INTO CrateItem (sku, quantity, crate_id) VALUES (?, ?, ?)', [1 => 'SKU-Q', 2 => 1, 3 => 'C-1'], 1);
        $log->keyHandedOut(3);
        $log->committed();

        self::assertSame(['facts' => [
            'crate C-1 items.4: null -> "SKU-P"',
            'crate C-1 items.3: null -> "SKU-Q"',
        ], 'unsure' => []], $this->replayed($log));
    }

    public function testARowWhoseKeyNobodyAskedTheConnectionForIsUnsureAndTakesNoOtherRowsKey(): void
    {
        $log = $this->begun();
        $log->executed('INSERT INTO CrateItem (sku, quantity, crate_id) VALUES (?, ?, ?)', [1 => 'SKU-P', 2 => 1, 3 => 'C-1'], 1);
        $log->executed('INSERT INTO CrateItem (sku, quantity, crate_id) VALUES (?, ?, ?)', [1 => 'SKU-Q', 2 => 1, 3 => 'C-1'], 1);
        $log->keyHandedOut(4);
        $log->committed();

        self::assertSame(['facts' => [
            'crate C-1 items.4: null -> "SKU-Q"',
        ], 'unsure' => ['an INSERT into CrateItem whose key the database handed out and nothing asked the connection for']], $this->replayed($log));
    }

    public function testAnEmptyingThatTookOtherThanTheRowsKnownIsUnsure(): void
    {
        $log = $this->begun();
        $log->executed('DELETE FROM CrateItem WHERE crate_id = ?', [1 => 'C-1'], 3);
        $log->committed();

        self::assertSame(['facts' => [], 'unsure' => ['an emptying of CrateItem took 3 rows where 2 were known']], $this->replayed($log));
    }

    public function testWhatCouldNotBeBoundIsUnsureUnlessItWasRolledBack(): void
    {
        $log = $this->begun();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->executed('DELETE FROM CrateItem WHERE sku = ?', [1 => 'SKU-X'], 1);
        $log->executed('ROLLBACK TO SAVEPOINT DOCTRINE_2', [], 0);
        $log->executed('UPDATE CrateItem SET quantity = 9 WHERE sku = ?', [1 => 'SKU-Y'], 1);
        $log->committed();

        self::assertSame(['facts' => [], 'unsure' => [
            'not bound: UPDATE CrateItem SET quantity = 9 WHERE sku = ? -- the WHERE names sku, which is not the key of '.CrateItem::class,
        ]], $this->replayed($log));
    }

    public function testAMoveIsADepartureAsTheRowWasAndAnArrivalAsItIsWithTheChangeBesideIt(): void
    {
        // One UPDATE moved the line and changed it. The owner it left sees it go as it was,
        // the owner it joined sees it come as it is, and what changed is the arrival's: the old
        // side is the line's value, not a state of C-2's.
        $log = $this->begun();
        $log->executed('UPDATE CrateItem SET quantity = ?, crate_id = ? WHERE id = ?', [1 => 5, 2 => 'C-2', 3 => 1], 1);
        $log->committed();

        self::assertSame(['facts' => [
            'crate C-1 items.1: "SKU-X" -> null',
            'crate C-2 items.1: null -> "SKU-X"',
            'crate C-2 items.1.quantity: 1 -> 5',
        ], 'unsure' => []], $this->replayed($log));
    }

    public function testARepresenterIsShownTheRowOnEachSideOfTheMove(): void
    {
        $log = $this->begun();
        $log->executed('UPDATE Chute SET size = ?, hopper_id = ? WHERE id = ?', [1 => 4, 2 => 2, 3 => 1], 1);
        $log->committed();

        self::assertSame(['facts' => [
            'hopper 1 chutes.1: "3" -> null',
            'hopper 2 chutes.1: null -> "4"',
            'hopper 2 chutes.1.size: 3 -> 4',
        ], 'unsure' => []], $this->replayed($log, rows: self::CHUTES));
    }

    public function testALineTakenOutOfEveryCollectionLeavesAndIsNobodysAfterwards(): void
    {
        $log = $this->begun();
        $log->executed('UPDATE CrateItem SET quantity = ?, crate_id = ? WHERE id = ?', [1 => 5, 2 => null, 3 => 1], 1);
        $log->committed();

        self::assertSame(['facts' => ['crate C-1 items.1: "SKU-X" -> null'], 'unsure' => []], $this->replayed($log));
    }

    public function testALineThatBelongedNowhereArrivesWithWhatChanged(): void
    {
        $log = $this->begun();
        $log->executed('UPDATE CrateItem SET quantity = ?, crate_id = ? WHERE id = ?', [1 => 5, 2 => 'C-1', 3 => 9], 1);
        $log->committed();

        self::assertSame(['facts' => [
            'crate C-1 items.9: null -> "SKU-LOOSE"',
            'crate C-1 items.9.quantity: 1 -> 5',
        ], 'unsure' => []], $this->replayed($log));
    }

    public function testAForeignKeyWrittenWithTheOwnerItAlreadyHadIsNoMove(): void
    {
        $log = $this->begun();
        $log->executed('UPDATE CrateItem SET quantity = ?, crate_id = ? WHERE id = ?', [1 => 5, 2 => 'C-1', 3 => 1], 1);
        $log->committed();

        self::assertSame(['facts' => ['crate C-1 items.1.quantity: 1 -> 5'], 'unsure' => []], $this->replayed($log));
    }

    public function testAnAssociationOfTheElementsOwnIsNeitherAFactNorADoubt(): void
    {
        // A chute's inspector is an association of the element: its history has nowhere to
        // declare a representer for it, so it is not something the element's history holds --
        // which is a rule of what is recorded, not something the replay could not follow.
        $log = $this->begun();
        $log->executed('UPDATE Chute SET inspector_id = ? WHERE id = ?', [1 => 7, 2 => 1], 1);
        $log->committed();

        self::assertSame(['facts' => [], 'unsure' => []], $this->replayed($log, rows: self::CHUTES));
    }

    private function begun(): StatementLog
    {
        $log = new StatementLog();
        $log->began();

        return $log;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $rows
     *
     * @return array{facts: list<string>, unsure: list<string>}
     */
    /** @return iterable<string, array{string, list<string>}> */
    public static function writesThatCannotBeRead(): iterable
    {
        // An alias is not a shape the persisters write: the statement is not read, and it
        // writes a table history is written about, however it spells the statement or the name.
        yield 'in lower case' => ['update CrateItem c set quantity = 2 where c.id = 2', ['not read: update CrateItem c set quantity = 2 where c.id = 2']];
        yield 'with the table quoted' => ['UPDATE `CrateItem` c SET quantity = 2 WHERE c.id = 2', ['not read: UPDATE `CrateItem` c SET quantity = 2 WHERE c.id = 2']];
        yield 'of a table nothing watched' => ['update Tag t set label = \'x\' where t.id = 2', []];
        // A comment before it does not hide it: read without the comment, it is the same doubt.
        yield 'behind a comment' => ['/* tag */ update CrateItem c set quantity = 2 where c.id = 2', ['not read: /* tag */ update CrateItem c set quantity = 2 where c.id = 2']];
        // One whose comment cannot be taken out may write anything anywhere.
        yield 'behind a comment the server executes' => ['/*!50000 x */ UPDATE CrateItem SET quantity = 2 WHERE id = 2', ['not read: /*!50000 x */ UPDATE CrateItem SET quantity = 2 WHERE id = 2']];
        yield 'of a table it cannot name' => ['UPDATE (SELECT id FROM CrateItem) x SET quantity = 2', ['not read: UPDATE (SELECT id FROM CrateItem) x SET quantity = 2']];
    }

    /**
     * @param list<string> $unsure
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writesThatCannotBeRead')]
    public function testAWriteThatCannotBeReadIsDoubtWhereItWritesAWatchedTable(string $sql, array $unsure): void
    {
        $log = $this->begun();
        $log->executed($sql, [], 1);
        $log->committed();

        self::assertSame(['facts' => [], 'unsure' => $unsure], $this->replayed($log));
    }

    /** @return iterable<string, array{string}> */
    public static function taggedWrites(): iterable
    {
        yield 'a comment before' => ['/* tag */ UPDATE CrateItem SET quantity = ? WHERE id = ?'];
        yield 'a comment inside' => ['UPDATE CrateItem SET quantity = ? /* why */ WHERE id = ?'];
        yield 'a comment after' => ["UPDATE CrateItem SET quantity = ? WHERE id = ? /*traceparent='00-ab'*/"];
        yield 'a line comment after' => ['UPDATE CrateItem SET quantity = ? WHERE id = ? -- tag'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('taggedWrites')]
    public function testAWriteWithACommentIsTheWriteItIs(string $sql): void
    {
        $log = $this->begun();
        $log->executed($sql, [1 => 2, 2 => 2], 1);
        $log->committed();

        self::assertSame(['facts' => ['crate C-1 items.2.quantity: 1 -> 2'], 'unsure' => []], $this->replayed($log));
    }

    private function replayed(StatementLog $log, array $rows = self::ROWS): array
    {
        $replayed = ShadowHistory::fromWhatWasRemembered($this->em, $rows)->replay($log, 0);

        return ['facts' => $replayed['facts'], 'unsure' => $replayed['unsure']]; // no flush labels these logs
    }
}
