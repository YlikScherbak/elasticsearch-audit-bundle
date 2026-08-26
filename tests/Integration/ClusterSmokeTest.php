<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the CI wiring: the client version under test can reach the cluster
 * version under test and round-trip a document. Every later integration test
 * builds on the same base class.
 */
#[Group('integration')]
final class ClusterSmokeTest extends ElasticsearchTestCase
{
    public function testTheClusterAnswersAndReportsItsVersion(): void
    {
        $info = self::client()->info()->asArray();

        self::assertArrayHasKey('version', $info);
        self::assertMatchesRegularExpression('/^[89]\./', $info['version']['number']);
    }

    public function testADocumentCanBeIndexedAndReadBack(): void
    {
        $index = $this->scratchIndex();

        try {
            self::client()->indices()->create(['index' => $index]);
            self::client()->index([
                'index'   => $index,
                'id'      => '1',
                'refresh' => 'true',
                'body'    => ['objectType' => 'order', 'objectId' => 42, 'event' => 'update'],
            ]);

            $hits = self::client()->search([
                'index' => $index,
                'body'  => ['query' => ['term' => ['objectId' => 42]]],
            ])->asArray()['hits']['hits'];

            self::assertCount(1, $hits);
            self::assertSame('order', $hits[0]['_source']['objectType']);
        } finally {
            $this->dropIndex($index);
        }
    }
}
