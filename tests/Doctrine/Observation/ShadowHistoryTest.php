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

    public function testRowsWhoseKeyTheDatabaseHandedOutAreBoundInTheOrderTheyWereAnnounced(): void
    {
        $log = $this->begun();
        $log->executed('INSERT INTO CrateItem (sku, quantity, crate_id) VALUES (?, ?, ?)', [1 => 'SKU-P', 2 => 1, 3 => 'C-1'], 1);
        $log->executed('INSERT INTO CrateItem (sku, quantity, crate_id) VALUES (?, ?, ?)', [1 => 'SKU-Q', 2 => 1, 3 => 'C-1'], 1);
        $log->committed();

        $p = new CrateItem('SKU-P');
        $p->id = 3;
        $q = new CrateItem('SKU-Q');
        $q->id = 4;

        self::assertSame(['facts' => [
            'crate C-1 items.3: null -> "SKU-P"',
            'crate C-1 items.4: null -> "SKU-Q"',
        ], 'unsure' => []], $this->replayed($log, [CrateItem::class => [$p, $q]]));
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

    private function begun(): StatementLog
    {
        $log = new StatementLog();
        $log->began();

        return $log;
    }

    /**
     * @param array<class-string, list<object>> $persisted
     *
     * @return array{facts: list<string>, unsure: list<string>}
     */
    private function replayed(StatementLog $log, array $persisted = []): array
    {
        return ShadowHistory::fromWhatWasRemembered($this->em, self::ROWS)->replay($log, 0, $persisted);
    }
}
