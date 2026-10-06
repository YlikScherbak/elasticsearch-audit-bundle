<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\TableName;
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

    public function testTheSameStatementIsReadOnce(): void
    {
        // A flush of twenty thousand rows writes one INSERT twenty thousand times.
        $sql = 'UPDATE Article SET title = ? WHERE id = ?';

        self::assertSame(StatementShape::read($sql), StatementShape::read($sql), 'the shape read before, not read again');
        self::assertNull(StatementShape::read('SELECT 1'), 'and a statement of no shape is remembered as none');
    }

    public function testAStatementReadIsStillRememberedAfterAnotherWellWithinTheBound(): void
    {
        $sql = 'UPDATE RememberedAlongside SET title = ? WHERE id = ?';
        $first = StatementShape::read($sql);
        StatementShape::read('DELETE FROM ReadInBetween WHERE id = ?');

        self::assertSame($first, StatementShape::read($sql));
    }

    public function testWhatIsRememberedOfStatementsIsBounded(): void
    {
        // A worker that runs for a week meets more statements than it should keep: past the
        // bound, the oldest are read again when they come back -- the same shape, a new reading.
        $first = StatementShape::read('DELETE FROM Oldest WHERE id = ?');

        for ($i = 0; $i < StatementShape::REMEMBERED; ++$i) {
            StatementShape::read(sprintf('DELETE FROM Other%d WHERE id = ?', $i));
        }

        $again = StatementShape::read('DELETE FROM Oldest WHERE id = ?');

        self::assertNotSame($first, $again, 'let go of, and read again');
        self::assertEquals($first, $again, 'reading the same');
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
        // Written the way an application writes it, tight against its punctuation.
        // A name of nothing in quotes is a name, and the quote that closes it closes it: SQLite
        // takes one, where PostgreSQL and MySQL refuse it.
        yield 'an empty quoted name' => ['UPDATE "" SET a = ? WHERE id = ?', ['update', '', ['a' => 1], ['id' => 2], true]];
        yield 'quoted names with nothing around the equals' => ['UPDATE "t" SET "a"=? WHERE "id"=?', ['update', 't', ['a' => 1], ['id' => 2], true]];
        yield 'an empty literal' => ["UPDATE t SET note = '' WHERE id = ?", ['update', 't', ['note' => null], ['id' => 1], true]];
        yield 'a literal of one escaped quote' => ["UPDATE t SET note = '''' WHERE id = ?", ['update', 't', ['note' => null], ['id' => 1], true]];
        yield 'a literal ending in an escaped quote, then a placeholder' => ["UPDATE t SET note = 'x''', a = ? WHERE id = ?", ['update', 't', ['note' => null, 'a' => 1], ['id' => 2], true]];
        yield 'a literal against the comma after it' => ["UPDATE t SET note = 'x',a = ? WHERE id = ?", ['update', 't', ['note' => null, 'a' => 1], ['id' => 2], true]];
        yield 'a number assigned' => ['UPDATE t SET a = 5, b = ? WHERE id = ?', ['update', 't', ['a' => null, 'b' => 1], ['id' => 2], true]];
        yield 'a minus that is no comment' => ['UPDATE t SET a = ? - 1 WHERE id = ?', ['update', 't', ['a' => null], ['id' => 2], true]];
        yield 'a division that is no comment' => ['UPDATE t SET a = ? / 2 WHERE id = ?', ['update', 't', ['a' => null], ['id' => 2], true]];
        yield 'names that begin with an underscore' => ['UPDATE _t SET _a = ? WHERE _id = ?', ['update', '_t', ['_a' => 1], ['_id' => 2], true]];
        yield 'a condition joined by a lower-case and' => ['DELETE FROM t WHERE a = ? and b = ?', ['delete', 't', [], ['a' => 1, 'b' => 2], true]];
        yield 'a literal with an escaped quote inside' => ["UPDATE t SET note = 'it''s' WHERE id = ?", ['update', 't', ['note' => null], ['id' => 1], true]];
        yield 'two literals in a list' => ["UPDATE t SET a = ? WHERE id = ? AND kind IN ('x','yz')", ['update', 't', ['a' => 1], ['id' => 2], false]];
        yield 'an insert whose values have no opening parenthesis' => ['INSERT INTO t (a) VALUES ?)', null];
        yield 'an update without SET' => ['UPDATE t a = ? WHERE id = ?', null];
        yield 'a word after the last condition' => ['DELETE FROM t WHERE id = ? LIMIT 1', ['delete', 't', [], [], false]];
        yield 'a reserved word in lower case as a bare column of an insert' => ['INSERT INTO t (a, from) VALUES (?, ?)', null];
        yield 'an insert with a column missing' => ['INSERT INTO t (, a) VALUES (?, ?)', null];
        yield 'an update with a column missing' => ['UPDATE t SET = ? WHERE id = ?', null];
        yield 'a parenthesis left open in a value' => ['UPDATE t SET a = (? WHERE id = ?', null];
        yield 'a block comment is not read' => ['DELETE FROM t WHERE id = ? /* why */', null];
        yield 'a block comment first is not read' => ['/* why */ DELETE FROM t WHERE id = ?', null];
        yield 'a named parameter is not read' => ['DELETE FROM t WHERE id = :id', null];
        yield 'a comment is not read' => ['DELETE FROM t WHERE id = ? -- why', null];
        yield 'a read is not read' => ['SELECT id FROM t WHERE id = ?', null];
        yield 'a CTE is not read' => ['WITH x AS (SELECT 1) UPDATE t SET a = ? WHERE id = ?', null];
        yield 'two rows at once is not read' => ['INSERT INTO t (a) VALUES (?), (?)', null];
        yield 'an INSERT from a SELECT is not read' => ['INSERT INTO t (a) SELECT a FROM u', null];
        yield 'an INSERT without INTO is not read' => ['INSERT t (a) VALUES (?)', null];
        yield 'an INSERT without its columns is not read' => ['INSERT INTO t VALUES (?)', null];
        yield 'an insert whose columns have no opening parenthesis' => ['INSERT INTO t a) VALUES (?)', null];
        yield 'fewer values than columns is not read' => ['INSERT INTO t (a, b) VALUES (?)', null];
        yield 'an UPDATE of every row is not read' => ['UPDATE t SET a = ?', null];
        yield 'a DELETE of every row is not read' => ['DELETE FROM t', null];
        yield 'an unterminated quote is not read' => ["UPDATE t SET a = 'x WHERE id = ?", null];
        yield 'nothing is not read' => ['', null];
    }

    /**
     * @param list<string|null> $expected
     */
    #[DataProvider('writesNotRead')]
    public function testWhatAStatementNotReadMayWrite(string $sql, array $expected): void
    {
        self::assertSame($expected, array_map(static fn (?TableName $name): ?string => $name?->written(), StatementShape::writesItCannotRead($sql)));
    }

    /**
     * A name part by part: a dot inside a quoted name is the name's, not a schema's.
     *
     * @return iterable<string, array{string, list<array{string, bool}>}>
     */
    public static function namesPartByPart(): iterable
    {
        yield 'a table' => ['DELETE FROM a WHERE id = ?', [['a', false]]];
        yield 'a schema and a table' => ['DELETE FROM main.Article WHERE id = ?', [['main', false], ['Article', false]]];
        yield 'one quoted name with a dot in it' => ['DELETE FROM "main.Article" WHERE id = ?', [['main.Article', true]]];
        yield 'a quoted schema and table' => ['UPDATE `s`.`a` SET n = ? WHERE id = ?', [['s', true], ['a', true]]];
        yield 'an insert' => ['INSERT INTO "Crate" (code) VALUES (?)', [['Crate', true]]];
    }

    /**
     * @param list<array{string, bool}> $parts
     */
    #[DataProvider('namesPartByPart')]
    public function testATableIsNamedPartByPart(string $sql, array $parts): void
    {
        self::assertSame($parts, StatementShape::read($sql)?->name->parts);
    }

    public function testANameNotReadIsNamedPartByPartToo(): void
    {
        self::assertSame([[['main.Article', true]], [['main', false], ['Article', false]]], array_map(
            static fn (?TableName $name): ?array => $name?->parts,
            StatementShape::writesItCannotRead('WITH x AS (SELECT 1) DELETE FROM "main.Article"; DELETE FROM main.Article'),
        ));
    }

    /**
     * @return iterable<string, array{string, list<string|null>}>
     */
    public static function writesNotRead(): iterable
    {
        yield 'a DELETE' => ['DELETE FROM a WHERE id = ?', ['a']];
        yield 'DDL' => ['CREATE TABLE a (id INT)', []];
        yield 'a word beginning with with' => ['WITHIN GROUP', []];
        yield 'a read' => ['WITH x AS (SELECT 1) SELECT * FROM x', []];
        yield 'lower case, and a DELETE' => ['with x as (select 1 as id) delete from a where id in (select id from x)', ['a']];
        yield 'an INSERT INTO' => ['WITH x AS (SELECT 1 AS id) INSERT INTO a (id) SELECT id FROM x', ['a']];
        yield 'an INSERT without INTO' => ['WITH x AS (SELECT 1 AS id) INSERT a (id) SELECT id FROM x', ['a']];
        yield 'an UPDATE' => ['WITH x AS (SELECT 1 AS id) UPDATE a SET b = 1 WHERE id IN (SELECT id FROM x)', ['a']];
        yield 'a write inside the expression' => ['WITH gone AS (DELETE FROM a RETURNING id) SELECT * FROM gone', ['a']];
        yield 'two writes' => ['WITH gone AS (DELETE FROM a RETURNING id) INSERT INTO b SELECT id FROM gone', ['a', 'b']];
        yield 'a schema' => ['WITH x AS (SELECT 1) DELETE FROM audit.a', ['audit.a']];
        yield 'a quoted name' => ['WITH x AS (SELECT 1) DELETE FROM "a b"', ['a b']];
        yield 'a quoted schema and name' => ['WITH x AS (SELECT 1) DELETE FROM `s`.`a`', ['s.a']];
        yield 'a bracketed name' => ['WITH x AS (SELECT 1) DELETE FROM [a]', ['a']];
        yield 'ONLY' => ['WITH x AS (SELECT 1) DELETE FROM ONLY a', ['a']];
        yield 'an UPDATE of ONLY' => ['WITH x AS (SELECT 1) UPDATE ONLY a SET b = 1', ['a']];
        yield 'a name that cannot be read' => ['WITH x AS (SELECT 1) DELETE FROM (SELECT 1)', [null]];
        yield 'a write in a literal' => ["WITH x AS (SELECT 'DELETE FROM a' AS t) SELECT t FROM x", []];
        yield 'a quote doubled in a literal' => ["WITH x AS (SELECT 'it''s DELETE FROM a' AS t) SELECT t FROM x", []];
        yield 'a backslash in an escape string' => ["WITH x AS (SELECT E'\\' DELETE FROM a' AS t) SELECT t FROM x", []];
        yield 'an escape string is not a word ending in e' => ["WITH x AS (SELECT name'DELETE' AS t) SELECT t FROM x", []];
        yield 'a dollar quote' => ['WITH x AS (SELECT $$DELETE FROM a$$ AS t) SELECT t FROM x', []];
        yield 'a tagged dollar quote' => ['WITH x AS (SELECT $q$DELETE FROM a$q$ AS t) SELECT t FROM x', []];
        yield 'a quoted name of a write' => ['WITH x AS (SELECT 1 AS "DELETE") SELECT * FROM x', []];
        yield 'FOR UPDATE' => ['WITH x AS (SELECT id FROM a FOR UPDATE) SELECT * FROM x', []];
        yield 'FOR NO KEY UPDATE' => ['WITH x AS (SELECT id FROM a FOR NO KEY UPDATE) SELECT * FROM x', []];
        yield 'ON CONFLICT DO UPDATE' => ['WITH x AS (SELECT 1 AS id) INSERT INTO a (id) SELECT id FROM x ON CONFLICT (id) DO UPDATE SET id = 1', ['a']];
        yield 'ON DUPLICATE KEY UPDATE' => ['WITH x AS (SELECT 1 AS id) INSERT INTO a (id) SELECT id FROM x ON DUPLICATE KEY UPDATE id = 1', ['a']];
        yield 'an unterminated literal' => ["WITH x AS (SELECT 'DELETE FROM a", []];
        yield 'an unterminated quoted name' => ['WITH x AS (SELECT 1) DELETE FROM "a', ['a']];
        yield 'REPLACE INTO' => ['REPLACE INTO a (id) VALUES (?)', ['a']];
        yield 'REPLACE with a modifier, without INTO' => ['REPLACE LOW_PRIORITY a (id) VALUES (?)', ['a']];
        yield 'REPLACE, the function' => ["SELECT REPLACE(name, 'a', 'b') FROM a", []];
        yield 'INSERT OR REPLACE' => ['INSERT OR REPLACE INTO a (id) VALUES (?)', ['a']];
        yield 'INSERT OR IGNORE' => ['INSERT OR IGNORE INTO a (id) VALUES (?)', ['a']];
        yield 'INSERT OR a word SQLite has not' => ['INSERT OR NOTHING INTO a (id) VALUES (?)', [null]];
        yield 'UPDATE OR REPLACE' => ['UPDATE OR REPLACE a SET b = 1', ['a']];
        yield 'INSERT IGNORE' => ['INSERT IGNORE INTO a (id) VALUES (?)', ['a']];
        yield 'INSERT with two modifiers' => ['INSERT LOW_PRIORITY IGNORE INTO a (id) VALUES (?)', ['a']];
        yield 'DELETE QUICK' => ['DELETE LOW_PRIORITY QUICK IGNORE FROM a WHERE id = ?', ['a']];
        yield 'UPDATE IGNORE' => ['UPDATE LOW_PRIORITY IGNORE a SET b = 1', ['a']];
        yield 'a MERGE and its arms' => ['MERGE INTO a USING b ON a.id = b.id WHEN MATCHED THEN UPDATE SET n = 1 WHEN MATCHED THEN DELETE WHEN NOT MATCHED THEN INSERT (id) VALUES (b.id)', ['a']];
        yield 'TRUNCATE TABLE' => ['TRUNCATE TABLE a', ['a']];
        yield 'TRUNCATE' => ['TRUNCATE a', ['a']];
        yield 'a foreign key that cascades' => ['CREATE TABLE b (a_id INT REFERENCES a (id) ON DELETE CASCADE ON UPDATE CASCADE)', []];
        yield 'a foreign key that sets null' => ['ALTER TABLE b ADD FOREIGN KEY (a_id) REFERENCES a (id) ON DELETE SET NULL', []];
        yield 'a column updated on its own' => ['CREATE TABLE a (at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)', []];

        // Where a literal or a quoted name ends: the words after it are read again.
        yield 'a write after an escape string that opens the statement' => ["E'\\'' DELETE FROM a", ['a']];
        yield 'a write after an escape string in lower case' => ["WITH x AS (SELECT e'\\'' AS t) DELETE FROM a", ['a']];
        yield 'a write after a literal behind a word ending in e' => ["WITH x AS (SELECT name'\\' AS t) DELETE FROM a", ['a']];
        yield 'a write after an escaped backslash' => ["WITH x AS (SELECT E'\\\\' AS t) DELETE FROM a", ['a']];
        yield 'a write after a doubled quote' => ["WITH x AS (SELECT 'it''s' AS t) DELETE FROM a", ['a']];
        yield 'a write right after a literal' => ["WITH x AS (SELECT 1) DELETE FROM a WHERE b = 'x'OR c IN (DELETE FROM d)", ['a', 'd']];
        yield 'a write after an empty quoted name' => ['WITH x AS (SELECT 1 AS "") DELETE FROM "a"', ['a']];
        yield 'a write after a dollar quote' => ['WITH x AS (SELECT $$a$$ AS t) DELETE FROM b', ['b']];
        yield 'a write after a positional parameter' => ['WITH x AS (SELECT $1 AS n) DELETE FROM a', ['a']];
        yield 'a write after a lock in lower case' => ['with x as (select id from a for update) delete from b', ['b']];
        yield 'a quoted name that is a word passed' => ['DELETE FROM "from"', ['from']];
        yield 'INSERT OR REPLACE in lower case' => ['insert or replace into a (id) values (?)', ['a']];
        yield 'INSERT OR a word SQLite has not, in lower case' => ['insert or nothing into a (id) values (?)', [null]];
        yield 'OR and its word passed only after INSERT and UPDATE' => ['DELETE OR REPLACE a', [null]];
        yield 'a write after a literal behind a symbol' => ["WITH x AS (SELECT ('\\') AS t) DELETE FROM a", ['a']];
        yield 'a write of a name beginning with e' => ['WITH x AS (SELECT 1) DELETE FROM entries', ['entries']];
        yield 'a literal behind a number and an E' => ["WITH x AS (SELECT 1E'\\' AS t) DELETE FROM a", ['a']];
        yield 'a literal that ends before a quote' => ["WITH x AS (SELECT 'a'x' DELETE FROM b", []];
        yield 'a doubled quote in an escape string' => ["WITH x AS (SELECT E'a''\\' DELETE FROM b' AS t) SELECT t FROM x", []];
        yield 'a write right after a literal, with no space' => ["SELECT 'x'DELETE FROM a", ['a']];

        // A write of several tables, MySQL's: any table -- which of them it writes is not read.
        yield 'an UPDATE with a join' => ['UPDATE scratch s JOIN Article a ON a.id = s.id SET a.title = ?', [null]];
        yield 'an UPDATE with a STRAIGHT_JOIN' => ['UPDATE scratch s STRAIGHT_JOIN Article a ON a.id = s.id SET a.title = ?', [null]];
        yield 'an UPDATE of two tables' => ['UPDATE scratch s, Article a SET a.title = ? WHERE a.id = s.id', [null]];
        yield 'a DELETE of names before FROM' => ['DELETE s, a FROM scratch s JOIN Article a ON a.id = s.id', [null]];
        yield 'a DELETE of an alias' => ['DELETE a FROM Article a JOIN scratch s ON a.id = s.id', [null]];
        yield 'a DELETE FROM two names USING' => ['DELETE FROM s, a USING scratch s JOIN Article a ON a.id = s.id', [null]];
        yield 'a DELETE of an alias, with no join' => ['DELETE a FROM Article a WHERE a.id = 1', [null]];
        yield 'a DELETE with modifiers of names before FROM' => ['DELETE LOW_PRIORITY a FROM Article a JOIN scratch s ON a.id = s.id', [null]];
        yield 'an UPDATE of two tables after an index hint' => ['UPDATE a USE INDEX (i1), b SET n = 1', [null]];
        yield 'an UPDATE with a join, in lower case' => ['update scratch s join article a on a.id = s.id set a.title = ?', [null]];
        yield 'OR and its word not passed after TRUNCATE' => ['TRUNCATE OR IGNORE a', [null]];
        yield 'a TRUNCATE of two tables' => ['TRUNCATE TABLE scratch, Article RESTART IDENTITY', [null]];
        // ... and not what only reads other tables, or a comma that is not the write's.
        yield 'a TRUNCATE of one table with its options' => ['TRUNCATE TABLE a RESTART IDENTITY CASCADE', ['a']];
        yield 'a DELETE USING other tables' => ['DELETE FROM a USING b, c WHERE a.id = b.id', ['a']];
        yield 'a DELETE with an alias and USING' => ['DELETE FROM a AS x USING b WHERE x.id = b.id', ['a']];
        yield 'an UPDATE FROM other tables' => ['UPDATE a SET n = 1 FROM b, c WHERE a.id = b.id', ['a']];
        yield 'an UPDATE with an alias' => ['UPDATE a AS x SET n = 1', ['a']];
        yield 'an UPDATE with index hints' => ['UPDATE a USE INDEX (i1, i2) SET n = 1', ['a']];
        yield 'a comma in another expression after the write\'s' => ['WITH g AS (DELETE FROM a), h AS (SELECT 1, 2) SELECT 1', ['a']];
        yield 'a comma in a quoted name' => ['UPDATE `a,b` SET n = 1', ['a,b']];
        yield 'a comma after the expression the write is in' => ['WITH g AS (DELETE FROM a) SELECT 1 FROM x, y', ['a']];
        yield 'a join after the expression the write is in' => ['WITH g AS (UPDATE a SET n = 1 RETURNING id) SELECT 1 FROM g JOIN y ON y.id = g.id', ['a']];
        yield 'a second statement after a write' => ['DELETE FROM a; SELECT 1 FROM x, y', ['a']];
    }
}
