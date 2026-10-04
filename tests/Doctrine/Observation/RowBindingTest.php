<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowBinding;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Catalogue;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CompositeLine;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vehicle;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\VersionedOrder;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Yard;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which rows a statement was about, against the fixtures' real mapping.
 *
 * The statements are the measured ones of {@see StatementShapeTest}; what is added is the
 * mapping, and the rule that a row is named only by its key's columns and nothing else.
 */
final class RowBindingTest extends DoctrineTestCase
{
    /**
     * @param array<int, mixed>        $params
     * @param array<int|string, mixed> $expected
     */
    #[DataProvider('statements')]
    public function testWhichRowsAStatementWasAbout(string $sql, array $params, array $expected): void
    {
        $shape = StatementShape::read($sql);
        self::assertNotNull($shape, 'the premise: the statement is read');

        $binding = RowBinding::of($this->em, $shape, $params);

        self::assertSame(
            $expected,
            array_filter(
                ['kind' => $binding->kind, 'class' => $binding->class, 'key' => $binding->key, 'association' => $binding->association, 'element' => $binding->element],
                static fn (mixed $value): bool => $value !== null,
            ),
            $sql.($binding->reason !== null ? ' -- '.$binding->reason : ''),
        );
    }

    public function testASchemaIsPartOfTheTablesName(): void
    {
        // A mapping with a schema is written schema-qualified, and only that statement is
        // its; the same table name without it is another table. The fixtures declare none, so
        // the mapping is one of its own, of tables in a schema; only the mapping is read.
        $config = new \Doctrine\ORM\Configuration();
        $config->setMetadataDriverImpl(new \Doctrine\ORM\Mapping\Driver\AttributeDriver([__DIR__.'/../Shop']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $shop = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);

        foreach ([['UPDATE Shop.ShelfLine SET shelf_id = ? WHERE id = ?', 'row'], ['UPDATE ShelfLine SET shelf_id = ? WHERE id = ?', 'unbound']] as [$sql, $kind]) {
            $shape = StatementShape::read($sql);
            self::assertNotNull($shape);
            self::assertSame($kind, RowBinding::of($shop, $shape, [1 => 2, 2 => 5])->kind, $sql);
        }
    }

    /**
     * @return iterable<string, array{string, array<int, mixed>, array<string, mixed>}>
     */
    public static function statements(): iterable
    {
        yield 'an update by id' => ['UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 2, 2 => 5], ['kind' => 'row', 'class' => CrateItem::class, 'key' => ['id' => 5]]];
        yield 'a delete by id' => ['DELETE FROM CrateItem WHERE id = ?', [1 => 5], ['kind' => 'row', 'class' => CrateItem::class, 'key' => ['id' => 5]]];
        yield 'an update by an assigned key' => ['UPDATE Crate SET status = ? WHERE code = ?', [1 => 'x', 2 => 'C-1'], ['kind' => 'row', 'class' => Crate::class, 'key' => ['code' => 'C-1']]];
        yield 'an insert whose key the database hands out' => ['INSERT INTO CrateItem (sku, quantity, crate_id) VALUES (?, ?, ?)', [1 => 'S', 2 => 1, 3 => 'C-1'], ['kind' => 'row', 'class' => CrateItem::class]];
        yield 'an insert with its key from a sequence' => ['INSERT INTO CrateItem (id, sku, quantity, crate_id) VALUES (?, ?, ?, ?)', [1 => 7, 2 => 'S', 3 => 1, 4 => 'C-1'], ['kind' => 'row', 'class' => CrateItem::class, 'key' => ['id' => 7]]];
        yield 'an insert with an assigned key' => ['INSERT INTO Crate (code, status, internalNote) VALUES (?, ?, ?)', [1 => 'C-9', 2 => 'packed', 3 => ''], ['kind' => 'row', 'class' => Crate::class, 'key' => ['code' => 'C-9']]];
        yield 'a versioned update: the version besides the key' => [
            'UPDATE VersionedOrder SET reference = ?, lockVersion = lockVersion + 1 WHERE id = ? AND lockVersion = ?',
            [1 => 'R-2', 2 => 3, 3 => 1],
            ['kind' => 'row', 'class' => VersionedOrder::class, 'key' => ['id' => 3]],
        ];
        yield 'a composite key' => ['UPDATE CompositeLine SET quantity = ? WHERE orderRef = ? AND position = ?', [1 => 4, 2 => 'O-1', 3 => 3], ['kind' => 'row', 'class' => CompositeLine::class, 'key' => ['orderRef' => 'O-1', 'position' => 3]]];
        yield 'half a composite key is not the key' => ['DELETE FROM CompositeLine WHERE orderRef = ?', [1 => 'O-1'], ['kind' => 'unbound']];
        yield 'JOINED, the child table binds to the root' => ['UPDATE Truck SET axles = ? WHERE id = ?', [1 => 3, 2 => 1], ['kind' => 'row', 'class' => Vehicle::class, 'key' => ['id' => 1]]];
        yield 'JOINED, the root table' => ['DELETE FROM Vehicle WHERE id = ?', [1 => 1], ['kind' => 'row', 'class' => Vehicle::class, 'key' => ['id' => 1]]];
        yield 'a collection emptied binds to its owner' => ['DELETE FROM CrateItem WHERE crate_id = ?', [1 => 'C-1'], ['kind' => 'rows of owner', 'class' => Crate::class, 'key' => ['code' => 'C-1'], 'association' => 'crate']];
        yield 'a join row added' => ['INSERT INTO catalogue_item (catalogue_code, item_id) VALUES (?, ?)', [1 => 'K-1', 2 => 2], ['kind' => 'join row', 'class' => Catalogue::class, 'key' => ['code' => 'K-1'], 'association' => 'items', 'element' => ['id' => 2]]];
        yield 'a join row removed' => ['DELETE FROM catalogue_item WHERE catalogue_code = ? AND item_id = ?', [1 => 'K-1', 2 => 1], ['kind' => 'join row', 'class' => Catalogue::class, 'key' => ['code' => 'K-1'], 'association' => 'items', 'element' => ['id' => 1]]];
        yield 'a many-to-many cleared' => ['DELETE FROM catalogue_item WHERE catalogue_code = ?', [1 => 'K-1'], ['kind' => 'join rows of owner', 'class' => Catalogue::class, 'key' => ['code' => 'K-1'], 'association' => 'items']];
        // What Doctrine writes when a target of a collection whose join columns do not cascade
        // is removed from the other side: every owner's row of it, and no owner named.
        yield 'a target taken out of every owner\'s many-to-many' => ['DELETE FROM catalogue_item WHERE item_id = ?', [1 => 2], ['kind' => 'join rows of target', 'class' => Catalogue::class, 'association' => 'items', 'element' => ['id' => 2]]];
        yield 'a join table named by neither side\'s key' => ['DELETE FROM catalogue_item WHERE item_id = ? AND position = ?', [1 => 2, 2 => 3], ['kind' => 'unbound']];
        yield 'DQL by a column that is not the key' => ['DELETE FROM CrateItem WHERE sku = ?', [1 => 'S'], ['kind' => 'unbound']];
        yield 'the key and another condition do not prove the row' => ['DELETE FROM CrateItem WHERE id = ? AND quantity = ?', [1 => 5, 2 => 1], ['kind' => 'unbound']];
        yield 'an inexact WHERE' => ['DELETE FROM CrateItem WHERE id = ? OR id = ?', [1 => 5, 2 => 6], ['kind' => 'unbound']];
        yield 'a table in another schema is another table' => ['UPDATE audit.CrateItem SET quantity = ? WHERE id = ?', [1 => 2, 2 => 5], ['kind' => 'unbound']];
        yield 'a table nothing maps' => ['DELETE FROM probe_row WHERE id = ?', [1 => 1], ['kind' => 'unbound']];
        // Only a DELETE by the one column pointing at an owner empties its collection; the owner
        // is whichever to-one that column is, not the first one declared.
        yield 'an update by the owner\'s column is no emptying' => ['UPDATE CrateItem SET quantity = ? WHERE crate_id = ?', [1 => 2, 2 => 'C-1'], ['kind' => 'unbound']];
        yield 'an emptying by a to-one declared after others' => ['DELETE FROM PackingCase WHERE yard_id = ?', [1 => 3], ['kind' => 'rows of owner', 'class' => Yard::class, 'key' => ['id' => 3], 'association' => 'yard']];
        // A join table is written by INSERT and DELETE alone: an UPDATE of it is the
        // application's, and neither an emptying nor a target taken out.
        yield 'a join table updated by its owner\'s column' => ['UPDATE catalogue_item SET item_id = ? WHERE catalogue_code = ?', [1 => 2, 2 => 'K-1'], ['kind' => 'unbound']];
        yield 'a join table updated by its target\'s column' => ['UPDATE catalogue_item SET catalogue_code = ? WHERE item_id = ?', [1 => 'K-2', 2 => 2], ['kind' => 'unbound']];
        yield 'an inexact WHERE on a join table' => ['DELETE FROM catalogue_item WHERE catalogue_code = ? OR item_id = ?', [1 => 'K-1', 2 => 2], ['kind' => 'unbound']];
        yield 'a join row inserted with its owner alone' => ['INSERT INTO catalogue_item (catalogue_code) VALUES (?)', [1 => 'K-1'], ['kind' => 'unbound']];
    }
}
