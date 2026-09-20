<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

/**
 * The request an export builds, and when it stops building them.
 *
 * The tests next door read what an export yields, which is the part an application
 * sees. This one reads what it *asks for*, because the failures that hurt most here are
 * invisible from the yielded side: an export that starts at the second batch skips the
 * beginning and still looks like a complete answer, one that counts the whole index on
 * every batch is correct and unusably slow, and one that asks a second time when there
 * is nothing left costs a round trip per export forever.
 *
 * They were all written after mutation testing reached src/Reader and found nothing
 * standing between any of them and a green suite.
 */
final class WhatAnExportAsksForTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testABatchIsFiveHundredUnlessTheCallerSaysOtherwise(): void
    {
        // The default is part of the contract: it is how many rows an export holds in
        // memory at once and how many round trips a million of them costs.
        $this->answerWith(0);

        iterator_to_array($this->reader()->iterate(AuditQuery::for('order')));

        self::assertSame(500, $this->gateway->searches[0]['body']['size']);
    }

    public function testAnExportStartsAtTheBeginning(): void
    {
        // A page number is still a number in the body, and the first batch of an export
        // is page one. Starting anywhere else would silently drop everything before it —
        // an export that is missing its first five hundred rows and says nothing.
        $this->answerWith(0);

        iterator_to_array($this->reader()->iterate(AuditQuery::for('order'), batchSize: 25));

        self::assertSame(0, $this->gateway->searches[0]['body']['from'] ?? 0, 'the first batch starts at row zero');
    }

    /**
     * @param bool $consistent whether the export reads from a point in time
     */
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testAnExportNeverAsksForAnExactCount(bool $consistent): void
    {
        // An exact total over the whole result set is a pass over the index, and an
        // export asks for one batch after another — so the count would be paid on every
        // one of them, for a number iterate() never reads.
        $this->answerWith(0);

        iterator_to_array($this->reader()->iterate(AuditQuery::for('order'), batchSize: 25, consistent: $consistent));

        self::assertFalse($this->gateway->searches[0]['body']['track_total_hits'], 'an export counts nothing');
    }

    public function testAnAnswerCarryingAViewIdIsNotAViewWhenNoViewWasOpened(): void
    {
        // pit_id in a response is only meaningful inside a point in time. Read without
        // asking whether one was opened, an ordinary answer that happens to carry the
        // field would leave the export holding an id it never obtained — and the finally
        // would close a view belonging to somebody else.
        $this->gateway->respondToSearch = static fn (): array => ['pit_id' => 'somebody-elses-view', 'hits' => ['total' => ['value' => 0], 'hits' => []]];

        iterator_to_array($this->reader()->iterate(AuditQuery::for('order'), batchSize: 25, consistent: false));

        self::assertSame([], $this->gateway->pointsInTime, 'no view was opened');
        self::assertSame([], $this->gateway->closed, 'and none was closed');
    }

    public function testAFullBatchWithNowhereToContinueFromIsTheLastOne(): void
    {
        // Three separate reasons to stop, and only one of them is "the batch was short".
        // A full batch whose last hit carries no sort values has nowhere to continue
        // from: asking again would repeat the same batch forever, and requiring all
        // three reasons at once is how that happens.
        $this->gateway->respondToSearch = static fn (): array => ['hits' => ['total' => ['value' => 2], 'hits' => [
            ['_id' => 'a', '_source' => self::record(1)],
            ['_id' => 'b', '_source' => self::record(2)],
        ]]];

        $entries = iterator_to_array($this->reader()->iterate(AuditQuery::for('order'), batchSize: 2, consistent: false), false);

        self::assertCount(2, $entries);
        self::assertCount(1, $this->gateway->searches, 'a batch with no cursor in it is the end of the export');
    }

    public function testAnEmptyBatchEndsTheExportRatherThanBeingDecorated(): void
    {
        // A batch that came back with nothing is the end, and the export stops there
        // instead of walking the rest of the loop over an empty list. What makes that
        // observable is the decorators: they are the application's code, and handing
        // them an empty page to decorate is work nobody asked for — once per export
        // today, and once per empty batch the day the loop gains another way round.
        $counted = new class implements \Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface {
            public int $calls = 0;

            /**
             * @param list<\Borsche\ElasticsearchAuditBundle\Model\AuditEntry> $entries
             *
             * @return list<\Borsche\ElasticsearchAuditBundle\Model\AuditEntry>
             */
            public function decorate(array $entries): array
            {
                ++$this->calls;

                return $entries;
            }
        };

        $this->answerWith(0);

        iterator_to_array(
            (new AuditReader($this->gateway, new IndexResolver('audit_log'), decorators: [$counted]))
                ->iterate(AuditQuery::for('order'), batchSize: 25, consistent: false),
        );

        self::assertSame(0, $counted->calls, 'there was nothing to decorate');
    }

    public function testAQueryThatMatchesNothingOffersNothingToContinueTo(): void
    {
        // The empty answer is built here rather than asked of the cluster, so the count
        // of rows it fetched is written by hand — and it is the number hasMore() reads.
        // Any other number invents a next page over a result set that does not exist:
        // one row too many says there is more, one too few says the page overran a total
        // of nothing.
        $this->answerWith(0);
        $reader = $this->reader();

        $plain = $reader->find(AuditQuery::for('order')->matchNothing()->page(1, 20));

        self::assertSame([], $plain->entries);
        self::assertFalse($plain->hasMore(), 'nothing matched, so there is no next page');

        // page() after after() would drop the cursor — it sets searchAfter to null — and
        // the page would answer from the other half of hasMore(). The order matters.
        $continued = $reader->find(AuditQuery::for('order')->matchNothing()->page(1, 1)->after(['x']));

        self::assertFalse($continued->hasMore(), 'and a cursor over nothing does not fill its page either');
        self::assertNull($continued->nextCursor());
        self::assertSame([], $this->gateway->searches, 'none of this asked the cluster anything');
    }

    private function answerWith(int $total): void
    {
        $this->gateway->respondToSearch = static fn (): array => ['hits' => ['total' => ['value' => $total], 'hits' => []]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(int $id): array
    {
        return ['objectType' => 'order', 'objectId' => $id, 'event' => 'update', 'loggedAt' => '2026-08-28 10:00:00', 'source' => 'a', 'changes' => []];
    }

    private function reader(): AuditReader
    {
        return new AuditReader($this->gateway, new IndexResolver('audit_log'));
    }
}
