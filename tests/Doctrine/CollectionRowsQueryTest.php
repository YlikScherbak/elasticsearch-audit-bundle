<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * The statement that asks whether a collection's rows are still there.
 *
 * Every shape below is one the listener's own tests cannot reach: a join table in a
 * schema needs a connection with two of them, an identifier that is an object needs an
 * entity mapped to one, and of each test for the shape of a mapping, one arm belongs to
 * the ORM major that is not installed. Inside the listener they were a dozen mutants
 * nothing could kill. Here the mapping is a parameter and the answer is a string.
 *
 * The metadata is real throughout — a hand-made ClassMetadata would be a second, worse
 * copy of Doctrine — and only the mapping is written by hand, which is exactly the part
 * that differs between the shapes.
 */
final class CollectionRowsQueryTest extends DoctrineTestCase
{
    public function testAnOwningManyToManyIsCountedInItsJoinTable(): void
    {
        $route = new Route('R-1');

        self::assertSame(
            ['SELECT COUNT(*) FROM route_stop WHERE route_id = ?', [null], ['integer']],
            $this->counting(Route::class, $route, $this->mappingOf(Route::class, 'stops')),
        );
    }

    public function testAJoinTableInASchemaIsNamedWithIt(): void
    {
        // A name on its own is a different table on a connection whose search path holds
        // one of the same name, and counting that one's rows would call a refusal an
        // emptying. Doctrine's own quote strategy qualifies it; so does this.
        $mapping = $this->mappingOf(Route::class, 'stops');
        $joinTable = $mapping['joinTable'];
        $joinTable['schema'] = 'shipping';
        $mapping['joinTable'] = $joinTable;

        [$sql] = $this->counting(Route::class, new Route('R-1'), $mapping) ?? [null];

        self::assertSame('SELECT COUNT(*) FROM shipping.route_stop WHERE route_id = ?', $sql);
    }

    public function testANameTheMappingCallsQuotedIsQuotedAndOneItDoesNotIsNot(): void
    {
        // Doctrine creates a table nobody asked it to quote with its name unquoted, so a
        // database that folds case stores it folded -- and a query that quotes it anyway
        // is asking for a table that is not there. Its own quote strategy goes by the flag
        // and so does this; quoting everything cost a whole road on Postgres, silently,
        // because the exception went through the failure policy.
        $mapping = $this->mappingOf(Route::class, 'stops');
        $joinTable = $mapping['joinTable'];
        $joinTable['quoted'] = true;
        $mapping['joinTable'] = $joinTable;

        [$sql] = $this->counting(Route::class, new Route('R-1'), $mapping) ?? [null];

        // Asked of the platform rather than written out: every one of them spells a quoted
        // name its own way, and a test that picks one spells the database it was written
        // on rather than the rule.
        $platform = $this->em->getConnection()->getDatabasePlatform();

        self::assertInstanceOf(AbstractPlatform::class, $platform);
        self::assertSame('SELECT COUNT(*) FROM '.$platform->quoteIdentifier('route_stop').' WHERE route_id = ?', $sql);
    }

    public function testAnInverseOneToManyIsCountedInTheElementsTable(): void
    {
        // Where a replaced collection with orphanRemoval deletes its rows, in one
        // statement and with no lifecycle event: the only witness there is for it.
        self::assertSame(
            ['SELECT COUNT(*) FROM CrateItem WHERE crate_id = ?', ['C-1'], ['string']],
            $this->counting(
                Crate::class,
                new Crate('C-1'),
                $this->mappingOf(Crate::class, 'items'),
                $this->em->getClassMetadata(CrateItem::class),
            ),
        );
    }

    public function testAMappingReadAsAnObjectAnswersTheSameAsOneReadAsAnArray(): void
    {
        // Which is the difference between the two supported ORM majors, and the arm of
        // every shape test that the installed one cannot take.
        $asArray = $this->mappingOf(Route::class, 'stops');
        $asObject = self::readableAsAnObject($asArray);

        self::assertSame(
            $this->counting(Route::class, new Route('R-1'), $asArray),
            $this->counting(Route::class, new Route('R-1'), $asObject),
        );
    }

    public function testAnAssociationOfNeitherShapeSaysNothing(): void
    {
        $mapping = $this->mappingOf(Route::class, 'stops');
        unset($mapping['joinTable'], $mapping['mappedBy']);

        self::assertNull($this->counting(Route::class, new Route('R-1'), $mapping));
    }

    public function testAJoinTableWithNoColumnsSaysNothing(): void
    {
        $mapping = $this->mappingOf(Route::class, 'stops');
        $joinTable = $mapping['joinTable'];
        $joinTable['joinColumns'] = [];
        $mapping['joinTable'] = $joinTable;

        self::assertNull($this->counting(Route::class, new Route('R-1'), $mapping));
    }

    public function testAColumnTheMappingDoesNotNameSaysNothing(): void
    {
        // Written out rather than taken from the metadata and spoiled: the newer ORM's
        // mapping objects will not hold a join column with no name, which is the shape
        // being asked about. The class under test takes a mapping as it finds it.
        $mapping = ['joinTable' => ['name' => 'route_stop', 'joinColumns' => [['referencedColumnName' => 'id']]]];

        self::assertNull($this->counting(Route::class, new Route('R-1'), $mapping));
    }

    public function testAnInverseShapeWithoutTheElementsMetadataSaysNothing(): void
    {
        self::assertNull($this->counting(Crate::class, new Crate('C-1'), $this->mappingOf(Crate::class, 'items')));
    }

    public function testEveryColumnOfACompositeKeyIsAskedAbout(): void
    {
        // One owner, two columns. The mapping is written by hand because no fixture has a
        // composite key, and the shape is what is being pinned: every column joined, every
        // value in order, every type beside it.
        $mapping = $this->mappingOf(Route::class, 'stops');
        $joinTable = $mapping['joinTable'];
        $joinTable['joinColumns'] = [
            ['name' => 'route_id', 'referencedColumnName' => 'id'],
            ['name' => 'route_id_again', 'referencedColumnName' => 'id'],
        ];
        $mapping['joinTable'] = $joinTable;

        self::assertSame(
            [
                'SELECT COUNT(*) FROM route_stop WHERE route_id = ? AND route_id_again = ?',
                [null, null],
                ['integer', 'integer'],
            ],
            $this->counting(Route::class, new Route('R-1'), $mapping),
        );
    }

    public function testAFieldWithNoDeclaredTypeIsLeftToTheDriver(): void
    {
        // Which is what a query with no types at all would do. The metadata is asked
        // first, so a column that stores an identifier as binary is converted the way it
        // stores it rather than handed over as whatever it casts to.
        self::assertSame('string', CollectionRowsQuery::entry(['x' => 'string'], 'x'));
        self::assertNull(CollectionRowsQuery::entry(['x' => 1], 'y'));
        self::assertNull(CollectionRowsQuery::entry('not a mapping at all', 'x'));
    }

    /**
     * @param class-string             $owner
     * @param ClassMetadata<object>|null $target
     *
     * @return array{0: string, 1: list<mixed>, 2: list<string>}|null
     */
    private function counting(string $owner, object $entity, mixed $mapping, ?ClassMetadata $target = null): ?array
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();

        self::assertInstanceOf(AbstractPlatform::class, $platform);

        return CollectionRowsQuery::counting($platform, $this->em->getClassMetadata($owner), $entity, $mapping, $target);
    }

    /**
     * @param class-string $of
     *
     * @return array<string, mixed>
     */
    private function mappingOf(string $of, string $field): array
    {
        $mapping = $this->em->getClassMetadata($of)->getAssociationMapping($field);

        // As an array either way, which is what the class under test reads it as and what
        // it is on the older of the two supported majors.
        return \is_array($mapping) ? $mapping : iterator_to_array(new \ArrayIterator((array) $mapping));
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private static function readableAsAnObject(array $mapping): \ArrayAccess
    {
        return new class($mapping) implements \ArrayAccess {
            /** @param array<string, mixed> $of */
            public function __construct(private readonly array $of)
            {
            }

            public function offsetExists(mixed $offset): bool
            {
                return \array_key_exists($offset, $this->of);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->of[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                throw new \LogicException('the mapping under test is read only');
            }

            public function offsetUnset(mixed $offset): void
            {
                throw new \LogicException('the mapping under test is read only');
            }
        };
    }
}
