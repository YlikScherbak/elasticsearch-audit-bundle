<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The golden corpus of what a statement says it does.
 *
 * The first group is every statement shape Doctrine's persisters were measured writing, on
 * ORM 2.19, 2.20 and 3.7, DBAL 3 and 4, SQLite, MySQL 8 and Postgres 16: the text is copied
 * from those runs, not composed. The second is the ways a statement can look like one of
 * them without being one. A new shape is a new entry here, read or deliberately not read --
 * never a third answer arrived at quietly.
 */
final class StatementShapeTest extends TestCase
{
    /**
     * @param array{0: string, 1: string, 2: array<string, int|null>, 3: array<string, int>, 4: bool}|null $expected
     */
    #[DataProvider('measured')]
    #[DataProvider('lookalikes')]
    public function testWhatAStatementSaysItDoes(string $sql, ?array $expected): void
    {
        $shape = StatementShape::read($sql);

        self::assertSame(
            $expected,
            $shape === null ? null : [$shape->kind, $shape->table, $shape->assigned, $shape->where, $shape->exact],
            $sql,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array{0: string, 1: string, 2: array<string, int|null>, 3: array<string, int>, 4: bool}|null}>
     */
    public static function measured(): iterable
    {
        yield 'an insert, identity column' => ['INSERT INTO CrateItem (sku, quantity, crate_id) VALUES (?, ?, ?)', ['insert', 'CrateItem', ['sku' => 1, 'quantity' => 2, 'crate_id' => 3], [], true]];
        yield 'an insert, id from a sequence (Postgres, DBAL 3)' => ['INSERT INTO CrateItem (id, sku, quantity, crate_id) VALUES (?, ?, ?, ?)', ['insert', 'CrateItem', ['id' => 1, 'sku' => 2, 'quantity' => 3, 'crate_id' => 4], [], true]];
        yield 'an insert, assigned key' => ['INSERT INTO Crate (code, status, internalNote) VALUES (?, ?, ?)', ['insert', 'Crate', ['code' => 1, 'status' => 2, 'internalNote' => 3], [], true]];
        yield 'an update by id' => ['UPDATE CrateItem SET quantity = ? WHERE id = ?', ['update', 'CrateItem', ['quantity' => 1], ['id' => 2], true]];
        yield 'an update by an assigned key' => ['UPDATE Crate SET status = ? WHERE code = ?', ['update', 'Crate', ['status' => 1], ['code' => 2], true]];
        yield 'a delete by id' => ['DELETE FROM CrateItem WHERE id = ?', ['delete', 'CrateItem', [], ['id' => 1], true]];
        yield 'a collection emptied' => ['DELETE FROM CrateItem WHERE crate_id = ?', ['delete', 'CrateItem', [], ['crate_id' => 1], true]];
        yield 'a versioned update: the key is not the last parameter' => [
            'UPDATE VersionedOrder SET reference = ?, lockVersion = lockVersion + 1 WHERE id = ? AND lockVersion = ?',
            ['update', 'VersionedOrder', ['reference' => 1, 'lockVersion' => null], ['id' => 2, 'lockVersion' => 3], true],
        ];
        yield 'a versioned insert' => ['INSERT INTO VersionedOrder (reference) VALUES (?)', ['insert', 'VersionedOrder', ['reference' => 1], [], true]];
        yield 'a composite key, updated' => ['UPDATE CompositeLine SET quantity = ? WHERE orderRef = ? AND position = ?', ['update', 'CompositeLine', ['quantity' => 1], ['orderRef' => 2, 'position' => 3], true]];
        yield 'a composite key, deleted' => ['DELETE FROM CompositeLine WHERE orderRef = ? AND position = ?', ['delete', 'CompositeLine', [], ['orderRef' => 1, 'position' => 2], true]];
        yield 'JOINED, the root row' => ['INSERT INTO Vehicle (plate, kind) VALUES (?, ?)', ['insert', 'Vehicle', ['plate' => 1, 'kind' => 2], [], true]];
        yield 'JOINED, the child row' => ['INSERT INTO Truck (id, axles) VALUES (?, ?)', ['insert', 'Truck', ['id' => 1, 'axles' => 2], [], true]];
        yield 'JOINED, the child updated' => ['UPDATE Truck SET axles = ? WHERE id = ?', ['update', 'Truck', ['axles' => 1], ['id' => 2], true]];
        yield 'a join row added' => ['INSERT INTO catalogue_item (catalogue_code, item_id) VALUES (?, ?)', ['insert', 'catalogue_item', ['catalogue_code' => 1, 'item_id' => 2], [], true]];
        yield 'a join row removed' => ['DELETE FROM catalogue_item WHERE catalogue_code = ? AND item_id = ?', ['delete', 'catalogue_item', [], ['catalogue_code' => 1, 'item_id' => 2], true]];
        yield 'a many-to-many cleared' => ['DELETE FROM catalogue_item WHERE catalogue_code = ?', ['delete', 'catalogue_item', [], ['catalogue_code' => 1], true]];
        yield 'DQL UPDATE: a literal assigned, not a key compared' => ['UPDATE CrateItem SET quantity = 9 WHERE sku = ?', ['update', 'CrateItem', ['quantity' => null], ['sku' => 1], true]];
        yield 'DQL DELETE' => ['DELETE FROM CrateItem WHERE sku = ?', ['delete', 'CrateItem', [], ['sku' => 1], true]];
    }

    /**
     * @return iterable<string, array{0: string, 1: array{0: string, 1: string, 2: array<string, int|null>, 3: array<string, int>, 4: bool}|null}>
     */
    public static function lookalikes(): iterable
    {
        yield 'quoted names, as Postgres and SQLite quote a reserved word' => [
            'UPDATE "order" SET "lockVersion" = "lockVersion" + 1, "status" = ? WHERE "id" = ? AND "lockVersion" = ?',
            ['update', 'order', ['lockVersion' => null, 'status' => 1], ['id' => 2, 'lockVersion' => 3], true],
        ];
        yield 'backticks, as MySQL quotes one' => ['DELETE FROM `order` WHERE `id` = ?', ['delete', 'order', [], ['id' => 1], true]];
        yield 'a schema is part of the name' => ['UPDATE audit.CrateItem SET quantity = ? WHERE id = ?', ['update', 'audit.CrateItem', ['quantity' => 1], ['id' => 2], true]];
        yield 'keywords in lower case' => ['update CrateItem set quantity = ? where id = ?', ['update', 'CrateItem', ['quantity' => 1], ['id' => 2], true]];
        yield 'a question mark in a literal is not a placeholder' => ["UPDATE t SET note = 'why?' WHERE id = ?", ['update', 't', ['note' => null], ['id' => 1], true]];
        yield 'nor after an escaped quote' => ["UPDATE t SET note = 'it''s?' WHERE id = ?", ['update', 't', ['note' => null], ['id' => 1], true]];
        yield 'an expression with a placeholder inside moves the numbering on' => [
            'UPDATE t SET a = COALESCE(?, a), b = ? WHERE id = ?',
            ['update', 't', ['a' => null, 'b' => 2], ['id' => 3], true],
        ];
        yield 'another condition beside the key is exact text and proves nothing more' => ['DELETE FROM t WHERE id = ? AND status = ?', ['delete', 't', [], ['id' => 1, 'status' => 2], true]];
        yield 'an IN is not exact' => ['DELETE FROM t WHERE id IN (?, ?)', ['delete', 't', [], [], false]];
        yield 'an IN beside a key still numbers what follows' => ['DELETE FROM t WHERE k IN (?, ?) AND id = ?', ['delete', 't', [], ['id' => 3], false]];
        yield 'an OR is not exact' => ['DELETE FROM t WHERE id = ? OR id = ?', ['delete', 't', [], ['id' => 2], false]];
        yield 'a literal compared is not exact' => ['DELETE FROM t WHERE id = 5', ['delete', 't', [], [], false]];
        yield 'IS NULL is not exact' => ['UPDATE t SET a = ? WHERE b IS NULL', ['update', 't', ['a' => 1], [], false]];
        yield 'a key with arithmetic after it is not a key compared' => ['DELETE FROM t WHERE id = ? + 1', ['delete', 't', [], [], false]];
        yield 'a parenthesised condition is not exact' => ['DELETE FROM t WHERE (id = ? OR id = ?)', ['delete', 't', [], [], false]];
        yield 'a named parameter is not read' => ['DELETE FROM t WHERE id = :id', null];
        yield 'a comment is not read' => ['DELETE FROM t WHERE id = ? -- why', null];
        yield 'a read is not read' => ['SELECT id FROM t WHERE id = ?', null];
        yield 'a CTE is not read' => ['WITH x AS (SELECT 1) UPDATE t SET a = ? WHERE id = ?', null];
        yield 'two rows at once is not read' => ['INSERT INTO t (a) VALUES (?), (?)', null];
        yield 'an INSERT from a SELECT is not read' => ['INSERT INTO t (a) SELECT a FROM u', null];
        yield 'fewer values than columns is not read' => ['INSERT INTO t (a, b) VALUES (?)', null];
        yield 'an UPDATE of every row is not read' => ['UPDATE t SET a = ?', null];
        yield 'a DELETE of every row is not read' => ['DELETE FROM t', null];
        yield 'an unterminated quote is not read' => ["UPDATE t SET a = 'x WHERE id = ?", null];
        yield 'nothing is not read' => ['', null];
    }
}
