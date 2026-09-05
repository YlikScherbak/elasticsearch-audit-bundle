<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use PHPUnit\Framework\TestCase;

final class IndexDefinitionTest extends TestCase
{
    public function testBaseMappingKeepsChangesUnindexed(): void
    {
        $definition = (new IndexDefinition())->toArray();

        self::assertSame(['number_of_shards' => 1, 'number_of_replicas' => 1], $definition['settings'], 'a copy by default: an audit trail is the last data to keep on one node only');
        self::assertSame(['type' => 'keyword'], $definition['mappings']['properties']['objectId']);
        self::assertSame(['type' => 'object', 'enabled' => false], $definition['mappings']['properties']['changes']);
        self::assertSame(['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'], $definition['mappings']['properties']['loggedAt']);
    }

    public function testTheRecordIdIsAKeyword(): void
    {
        self::assertSame(['type' => 'keyword'], (new IndexDefinition())->properties()['id']);
    }

    public function testUndeclaredFieldsAreStoredButNotIndexed(): void
    {
        self::assertFalse((new IndexDefinition())->toArray()['mappings']['dynamic'], 'dynamic: false — a field without a declared mapping must not be guessed at (text for a keyword, long for a later string...)');
    }

    public function testObjectIdCanBeAnInteger(): void
    {
        $definition = new IndexDefinition(IndexDefinition::OBJECT_ID_INTEGER);

        self::assertSame(['type' => 'integer'], $definition->properties()['objectId']);
    }

    public function testEnricherPropertiesAreAppended(): void
    {
        $definition = (new IndexDefinition())->withProperties(['salesType' => ['type' => 'integer']]);

        self::assertSame(['type' => 'integer'], $definition->properties()['salesType']);
        self::assertArrayHasKey('objectType', $definition->properties());
    }

    public function testBaseFieldsCannotBeRemapped(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new IndexDefinition())->withProperties(['loggedAt' => ['type' => 'keyword']]);
    }

    public function testUnknownObjectIdTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IndexDefinition('text');
    }

    public function testANumericIdentifierCanBeSixtyFourBits(): void
    {
        // Elasticsearch's integer stops at 2147483647, which a bigint primary key
        // outgrows; long is the type to reach for and integer the one to regret.
        $mapping = (new IndexDefinition(IndexDefinition::OBJECT_ID_LONG))->toArray();

        self::assertSame(['type' => 'long'], $mapping['mappings']['properties']['objectId']);
    }
}
