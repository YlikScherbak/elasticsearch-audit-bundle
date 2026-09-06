<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that talk to a live Elasticsearch.
 *
 * They run only when AUDIT_ES_URL points at a cluster (see docker-compose.yml)
 * and are excluded from the default suite by the "integration" group.
 */
abstract class ElasticsearchTestCase extends TestCase
{
    private static ?Client $client = null;

    protected static function client(): Client
    {
        $url = getenv('AUDIT_ES_URL') ?: ($_ENV['AUDIT_ES_URL'] ?? '');

        if ($url === '') {
            self::markTestSkipped('Set AUDIT_ES_URL to run the Elasticsearch integration tests.');
        }

        return self::$client ??= ClientBuilder::create()
            ->setHosts([$url])
            ->setSSLVerification(false)
            ->build();
    }

    /** @var list<string> indices this test asked for, dropped when it ends */
    private array $scratch = [];

    /**
     * A throwaway index name unique to the test, so parallel or repeated runs never collide.
     *
     * Cleaning them up is this class's job rather than each test's: a setUp() that skips
     * before it has named its index left the subclass's tearDown() reaching for a
     * property nobody set, and the error stood where the skip should have been.
     */
    protected function scratchIndex(): string
    {
        return $this->scratch[] = 'audit_test_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $index) {
            $this->dropIndex($index);
        }

        $this->scratch = [];
    }

    protected function dropIndex(string $index): void
    {
        self::client()->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
    }
}
