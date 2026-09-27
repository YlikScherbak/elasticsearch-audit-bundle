<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * No table or column of a fixture is a word a supported database reserves.
 *
 * Nothing quotes them, so a column called `force` or `values` works on SQLite and fails on
 * MySQL -- found three times by the database matrix, ten minutes in, and each time a fixture
 * written that day. The keyword lists DBAL keeps for each platform say it in a second.
 */
final class NoFixtureNamesAWordADatabaseReservesTest extends DoctrineTestCase
{
    public function testEveryNameAFixtureGivesTheDatabaseIsOneItTakesUnquoted(): void
    {
        $lists = self::theKeywordLists();
        self::assertNotSame([], $lists, 'the premise: DBAL has keyword lists to read');

        $reserved = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!$metadata instanceof ClassMetadata) {
                continue;
            }

            foreach (self::namesOf($metadata) as $name) {
                foreach ($lists as $platform => $list) {
                    if ($list->isKeyword($name)) {
                        $reserved[] = sprintf('%s: "%s" (%s)', $metadata->name, $name, $platform);
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($reserved)), 'a name one of the supported databases reserves; call it something else');
    }

    /**
     * @return array<string, KeywordList>
     */
    private static function theKeywordLists(): array
    {
        $lists = [];

        foreach ([
            'MySQL 8' => 'Doctrine\\DBAL\\Platforms\\Keywords\\MySQL80Keywords',
            'PostgreSQL' => 'Doctrine\\DBAL\\Platforms\\Keywords\\PostgreSQLKeywords',
            'SQLite' => 'Doctrine\\DBAL\\Platforms\\Keywords\\SQLiteKeywords',
        ] as $platform => $class) {
            if (class_exists($class)) {
                $list = new $class();
                self::assertInstanceOf(KeywordList::class, $list);
                $lists[$platform] = $list;
            }
        }

        return $lists;
    }

    /**
     * Every name a mapping hands the database: its table, its columns, its foreign keys, its
     * discriminator, its join tables and theirs.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    private static function namesOf(ClassMetadata $metadata): array
    {
        $names = [$metadata->getTableName()];

        foreach ($metadata->getFieldNames() as $field) {
            $names[] = $metadata->getColumnName($field);
        }

        $discriminator = CollectionRowsQuery::entry($metadata->discriminatorColumn, 'name');

        if (\is_string($discriminator)) {
            $names[] = $discriminator;
        }

        foreach ($metadata->getAssociationNames() as $association) {
            $mapping = $metadata->getAssociationMapping($association);

            foreach ([CollectionRowsQuery::entry($mapping, 'joinColumns'), CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'joinColumns'), CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'inverseJoinColumns')] as $columns) {
                foreach (\is_iterable($columns) ? $columns : [] as $column) {
                    $name = CollectionRowsQuery::entry($column, 'name');

                    if (\is_string($name)) {
                        $names[] = $name;
                    }
                }
            }

            $joinTable = CollectionRowsQuery::entry(CollectionRowsQuery::entry($mapping, 'joinTable'), 'name');

            if (\is_string($joinTable)) {
                $names[] = $joinTable;
            }
        }

        return array_values(array_unique($names));
    }
}
