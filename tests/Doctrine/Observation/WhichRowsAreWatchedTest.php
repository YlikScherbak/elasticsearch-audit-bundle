<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\WatchedRows;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop\BoxedRackItem;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop\Label;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop\Load;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\ShopPlate\DeskPlate;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\ShopPlate\Drawer;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop\Shelf;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop\ShelfLine;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;

/**
 * What the mapping makes history, read from a mapping the fixtures do not have: tables in a
 * schema and in mixed case, a link that is history beside one that is not, a line class whose
 * own collection comes before its owner, and an inverse one-to-one. A table's name is the one a
 * statement carries ({@see StatementShape}), compared as the database compares an unquoted one.
 */
final class WhichRowsAreWatchedTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function tables(): iterable
    {
        yield 'an audited entity\'s, in its schema' => ['Shop.Shelf', true];
        yield 'a watched link\'s join table, in its schema' => ['Shop.Shelf_Label', true];
        yield 'the same in another case' => ['shop.shelf_label', true];
        yield 'a watched link\'s target' => ['Shop.Label', true];
        yield 'a line\'s' => ['Shop.ShelfLine', true];
        yield 'the join table without its schema' => ['Shelf_Label', false];
        yield 'the join table of a link that is no history' => ['Shop.Shelf_Hidden', false];
        yield 'the join table of a line\'s own link' => ['Shop.ShelfLine_Label', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testATableIsHistoryAsTheMappingSays(string $table, bool $history): void
    {
        $em = self::manager();
        $shape = StatementShape::read('DELETE FROM '.$table.' WHERE id = ?');

        self::assertNotNull($shape);
        self::assertSame($history, (new WatchedRows())->isAHistoryTable($em, $shape->table));
    }

    public function testALineIsWatchedWhateverItDeclaresBeforeItsOwner(): void
    {
        $em = self::manager();

        self::assertTrue((new WatchedRows())->areWatched($em, $em->getClassMetadata(ShelfLine::class)));
    }

    public function testASubclassOfTheClassACollectionHoldsIsWatched(): void
    {
        $em = self::manager();

        self::assertTrue((new WatchedRows())->areWatched($em, $em->getClassMetadata(BoxedRackItem::class)));
    }

    public function testTheRootOfTheSubclassACollectionHoldsIsWatched(): void
    {
        // Its rows hold the elements: one table for the hierarchy, and a statement of it is
        // read by the root.
        $em = self::manager();

        self::assertTrue((new WatchedRows())->areWatched($em, $em->getClassMetadata(Load::class)));
    }

    public function testTheOwningSideOfAnAuditedOneToOneIsNoLine(): void
    {
        // An inverse one-to-one names the field it is mapped by, as an inverse collection does;
        // its target is a single row, not an element of anything.
        if (class_exists(\Doctrine\ORM\Mapping\AssociationMapping::class) && !class_exists(\Doctrine\ORM\Mapping\OneToOneOwningSideMapping::class)) {
            self::markTestSkipped('ORM 3.0.0 cannot load an owning one-to-one.');
        }

        $em = self::manager('ShopPlate');
        $watched = new WatchedRows();

        self::assertFalse($watched->areWatched($em, $em->getClassMetadata(DeskPlate::class)));
        self::assertTrue($watched->areWatched($em, $em->getClassMetadata(Drawer::class)));
    }

    public function testATargetOfAWatchedLinkIsNoLine(): void
    {
        $em = self::manager();
        $watched = new WatchedRows();

        self::assertFalse($watched->areWatched($em, $em->getClassMetadata(Label::class)));
        self::assertTrue($watched->areWatched($em, $em->getClassMetadata(Shelf::class)));
    }

    private static function manager(string $directory = 'Shop'): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../'.$directory]));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
    }
}
