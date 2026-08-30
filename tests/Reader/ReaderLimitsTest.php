<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

/**
 * How large a page may be and how deep it may reach are properties of the deployment,
 * so the reader holds them and refuses a query that would exceed them — before the
 * cluster answers 400, and naming the setting to raise.
 */
final class ReaderLimitsTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->gateway->respondToSearch = static fn () => ['hits' => ['total' => ['value' => 0], 'hits' => []]];
    }

    public function testTheDefaultsAreElasticsearchsOwn(): void
    {
        $reader = $this->reader();

        self::assertSame(0, $reader->find(AuditQuery::for('order')->page(10, 1000))->total, '10 000 rows deep is allowed');

        try {
            $reader->find(AuditQuery::for('order')->page(2, 1000)->page(11, 1000));
            self::fail('expected InvalidQueryException');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('reader.max_result_window (10000)', $e->getMessage());
            self::assertStringContainsString('after()', $e->getMessage(), 'the message points at the way out');
        }
    }

    public function testAPageLargerThanTheConfiguredLimitIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('A page of 5000 is larger than reader.max_limit (1000)');

        $this->reader()->find(AuditQuery::for('order')->page(1, 5000));
    }

    public function testRaisingTheSettingsIsAllThatStandsBetweenAScreenAndTenThousandRows(): void
    {
        $reader = $this->reader(maxLimit: 10_000, maxWindow: 50_000);

        // What the customer asked for: five pages of ten thousand.
        $reader->find(AuditQuery::for('order')->page(5, 10_000));

        self::assertSame(40_000, $this->gateway->searches[0]['body']['from']);
        self::assertSame(10_000, $this->gateway->searches[0]['body']['size']);

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('past reader.max_result_window (50000)');

        $reader->find(AuditQuery::for('order')->page(6, 10_000));
    }

    public function testACursorHasNoCeilingAtAll(): void
    {
        $reader = $this->reader();

        // Far past the window, which a cursor does not care about.
        $reader->find(AuditQuery::for('order')->page(1, 1000)->after(['2026-08-28 10:00:00', 'id-1']));

        self::assertArrayNotHasKey('from', $this->gateway->searches[0]['body']);
    }

    public function testACursorIsStillHeldToThePageSize(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('larger than reader.max_limit');

        $this->reader()->find(AuditQuery::for('order')->page(1, 5000)->after(['x']));
    }

    public function testAnExportBatchIsHeldToTheSameLimit(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('A page of 2000 is larger than reader.max_limit (1000)');

        iterator_to_array($this->reader()->iterate(AuditQuery::for('order'), batchSize: 2000, consistent: false));
    }

    public function testWhatAnExtensionDidIsWhatGetsChecked(): void
    {
        $greedy = new class implements QueryExtensionInterface {
            public function extend(AuditQuery $query): AuditQuery
            {
                return $query->page(1, 4000);
            }
        };

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('A page of 4000');

        $this->reader(extensions: [$greedy])->find(AuditQuery::for('order')->page(1, 20));
    }

    public function testThePageKnowsHowFarPageNumbersReach(): void
    {
        $this->gateway->respondToSearch = static fn () => [
            'hits' => ['total' => ['value' => 1_000_000], 'hits' => []],
        ];

        $page = $this->reader(maxLimit: 10_000, maxWindow: 50_000)->find(AuditQuery::for('order')->page(1, 10_000));

        self::assertSame(100, $page->totalPages(), 'a hundred pages exist');
        self::assertSame(5, $page->maxReachablePage(), 'five of them can be asked for by number');
    }

    public function testAPageReadByCursorSaysSo(): void
    {
        $this->gateway->respondToSearch = static fn () => [
            'hits' => [
                'total' => ['value' => 1_000_000],
                'hits' => [['_id' => 'a', '_source' => ['objectType' => 'order', 'objectId' => 1, 'event' => 'update', 'loggedAt' => '2026-08-30 10:00:00'], 'sort' => ['2026-08-30 10:00:00', 'a']]],
            ],
        ];

        $page = $this->reader()->find(AuditQuery::for('order')->page(1, 1)->after(['2026-08-30 09:00:00', 'x']));

        self::assertTrue($page->usesCursor);
        self::assertTrue($page->hasMore(), 'a full batch: there may be more');
        self::assertSame(['2026-08-30 10:00:00', 'a'], $page->nextCursor());
        self::assertNotNull($page->nextCursorToken());
    }

    /**
     * @param iterable<QueryExtensionInterface> $extensions
     */
    private function reader(int $maxLimit = AuditQuery::DEFAULT_MAX_LIMIT, int $maxWindow = AuditQuery::DEFAULT_MAX_WINDOW, iterable $extensions = []): AuditReader
    {
        return new AuditReader($this->gateway, new IndexResolver('audit_log'), extensions: $extensions, maxLimit: $maxLimit, maxResultWindow: $maxWindow);
    }
}
