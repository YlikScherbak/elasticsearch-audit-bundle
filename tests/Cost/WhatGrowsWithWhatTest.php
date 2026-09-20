<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Cost;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How the work grows with the size of the operation.
 *
 * Every other test here asks what the bundle produces. This one asks what it costs, and
 * it asks in operations rather than in seconds: a stopwatch on a shared runner measures
 * the runner, and a threshold in milliseconds either flakes or is so loose it catches
 * nothing. The number of requests, the number of comparator calls and the bytes still
 * held after a frame closes are properties of the code, and they read the same on a
 * laptop and on a busy CI machine.
 *
 * What they are here to catch is a change of *class*, not a regression of a few percent:
 * a quadratic cost is invisible at the sizes a test suite uses and fatal at the sizes
 * production uses.
 *
 * **And half of that class is invisible here too, which is worth saying plainly.** Each
 * of these watches one countable thing, and a cost that avoids that thing does not move
 * the number. An array copied on every arriving element, a second pass over what the
 * buffer already holds, a sort where a scan would do — none of them make another
 * request, ask the comparator again or retain a byte. The quadratic cost this bundle
 * actually shipped was one of those: a frame that copied everything it held on every
 * arrival, four seconds instead of two hundred milliseconds at four thousand elements,
 * and every count in this file would have stayed exactly where it was.
 *
 * Only a clock sees those, and a clock on a shared runner measures the runner. So the
 * counts are what this file asserts, the names say "requests" and "comparisons" rather
 * than "work", and a cost of the other kind is measured by hand against real sizes —
 * which is how that one was found and how the next one will be.
 */
final class WhatGrowsWithWhatTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function batches(): iterable
    {
        yield 'a single record' => [1, 1];
        yield 'one short of a batch' => [499, 1];
        yield 'exactly a batch' => [500, 1];
        yield 'one past a batch' => [501, 2];
        yield 'two batches' => [1_000, 2];
        yield 'three' => [1_500, 3];
    }

    #[DataProvider('batches')]
    public function testARequestPerBatchAndNotOnePerRecord(int $records, int $expected): void
    {
        // The flush that touches fifty entities is one _bulk call, and the import that
        // touches fifty thousand is a hundred — not fifty thousand round trips, which is
        // what this cost looked like before batching and what it would look like again
        // if the chunking were dropped.
        $gateway = new InMemoryGateway();
        $transport = new SyncTransport($gateway);

        $writer = new AuditWriter(
            $transport,
            $transport,
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'tests'),
            new FrozenClock(),
            batchSize: 500,
        );

        $writer->writeAll(array_map(
            static fn (int $id): AuditRecord => new AuditRecord('order', $id, AuditEvent::UPDATE, actor: 'u'),
            range(1, $records),
        ));

        self::assertCount($expected, $gateway->bulks, sprintf('%d records is ceil(%d / 500) requests', $records, $records));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function exports(): iterable
    {
        // One request per full batch, and one more that comes back short or empty and
        // ends the export. A result set that divides exactly still pays for the last
        // one: nothing in the answer says "that was the end" until an answer says so.
        yield 'ten in twos' => [10, 2, 6];
        yield 'ten in fives' => [10, 5, 3];
        yield 'ten in tens' => [10, 10, 2];
        yield 'twelve in fives' => [12, 5, 3];
        yield 'nothing at all' => [0, 5, 1];
    }

    #[DataProvider('exports')]
    public function testAnExportAsksOncePerFullBatchAndOnceToFindTheEnd(int $records, int $batchSize, int $expected): void
    {
        $gateway = new InMemoryGateway();

        for ($id = 1; $id <= $records; ++$id) {
            $gateway->index('audit_log', ['objectType' => 'order', 'objectId' => $id, 'event' => 'update', 'loggedAt' => sprintf('2026-08-28 10:%02d:00', $id), 'source' => 'a', 'changes' => []]);
        }

        // An index with no documents in it would be missing rather than empty, and the
        // export would fail for a reason that has nothing to do with counting requests.
        $gateway->indices['audit_log'] ??= [];

        $reader = new AuditReader($gateway, new IndexResolver('audit_log'));
        $entries = iterator_to_array($reader->iterate(AuditQuery::for('order'), batchSize: $batchSize), false);

        self::assertCount($records, $entries, 'the premise: the export read everything');
        self::assertSame($expected, $gateway->pointsInTime['pit-1']['searches'] ?? 0, sprintf('%d rows in batches of %d is intdiv(%d, %d) + 1 requests', $records, $batchSize, $records, $batchSize));
    }

    public function testMergingCostsNothingAtComparisonTime(): void
    {
        // The comparator is the application's code and the expensive part of closing a
        // frame: it decides whether a field really moved. It is asked once per field of
        // each record the frame lets go — so an object saved three thousand times in one
        // operation costs one comparison, not three thousand, and the merging that makes
        // the trail readable does not make closing it slower.
        $once = self::comparisonsFor(objects: 1, savesEach: 1);
        $athousand = self::comparisonsFor(objects: 1, savesEach: 1_000);
        $threethousand = self::comparisonsFor(objects: 1, savesEach: 3_000);

        self::assertSame($once, $athousand, 'a thousand saves of one object is still one record to compare');
        self::assertSame($once, $threethousand);
    }

    public function testTheComparisonsGrowWithTheObjectsAndNotFasterThanThem(): void
    {
        // Three times the objects, at most three times the comparisons plus a constant.
        // What this refuses is the shape and not the constant: a comparison per pair of
        // objects clears it by a mile at these sizes, and one per object comes nowhere
        // near it.
        //
        // Comparisons, and that is the whole of what it says: a quadratic cost that does
        // not go through the comparator leaves this number exactly where it is. See the
        // class docblock, which names the one this bundle actually shipped — it was of
        // that other kind.
        $thousand = self::comparisonsFor(objects: 1_000, savesEach: 2);
        $threethousand = self::comparisonsFor(objects: 3_000, savesEach: 2);

        self::assertGreaterThan(0, $thousand, 'the premise: this costs something');
        self::assertLessThanOrEqual(
            3 * $thousand + 100,
            $threethousand,
            sprintf('three times the objects cost %d comparisons against %d for a third of them', $threethousand, $thousand),
        );
    }

    public function testAFrameKeepsNothingAfterItCloses(): void
    {
        // A worker opens and closes a frame per message and runs for weeks. Anything a
        // closed frame keeps — a record, a key, a note about what moved — is a leak
        // measured in days, and it is invisible to every test that opens one frame.
        //
        // Measured as a difference between two sizes rather than as an absolute: what a
        // process has allocated by this point is not this test's business, and five
        // times the frames retaining the same bytes is the fact worth asserting.
        $thousand = self::bytesRetainedAcross(1_000);
        $fivethousand = self::bytesRetainedAcross(5_000);

        self::assertLessThan(
            $thousand + 256 * 1024,
            $fivethousand,
            sprintf('five thousand frames retained %d bytes against %d for a thousand, so something is kept per frame', $fivethousand, $thousand),
        );
    }

    private static function comparisonsFor(int $objects, int $savesEach): int
    {
        $comparator = new class implements ValueComparatorInterface {
            public int $calls = 0;

            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                ++$this->calls;

                return null; // no opinion: the buffer falls back to its own comparison
            }
        };

        // High enough that the valve never opens: this is about the cost of merging, and
        // an early release would end the merging under test.
        $buffer = new FrameBuffer($comparator, maxHeld: 1_000_000);
        $buffer->open();

        for ($save = 0; $save < $savesEach; ++$save) {
            for ($id = 1; $id <= $objects; ++$id) {
                $buffer->hold(new AuditRecord('order', $id, AuditEvent::UPDATE, actor: 'u', changes: [
                    'status' => new Change('a', 'b'.$save),
                ]));
            }
        }

        $buffer->close();

        return $comparator->calls;
    }

    private static function bytesRetainedAcross(int $frames): int
    {
        gc_collect_cycles();
        $before = memory_get_usage();

        $buffer = new FrameBuffer(maxHeld: 10);

        for ($i = 1; $i <= $frames; ++$i) {
            $buffer->open();
            $buffer->hold(new AuditRecord('order', $i, AuditEvent::UPDATE, actor: 'u', changes: ['status' => new Change('a', 'b')]));
            $buffer->close();
        }

        gc_collect_cycles();

        return memory_get_usage() - $before;
    }
}
