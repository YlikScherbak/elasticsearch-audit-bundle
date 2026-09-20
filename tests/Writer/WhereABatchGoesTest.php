<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

/**
 * Where a batch of records ends up, and what a batch that cannot be written says.
 *
 * write() and writeAll() are the same promise arrived at twice — one record and many —
 * and the second one is the path a flush takes, which is the path almost every record
 * in production is on. The two have to answer the same way about the three things that
 * are not a plain send: a frame that publishes nothing until it closes, a frame that
 * refuses to grow, and the choice between the two transports.
 *
 * Each of these is one statement in the middle of a loop, and losing one of them is
 * silent: the records are built, nothing throws, and they are simply not there.
 */
final class WhereABatchGoesTest extends TestCase
{
    public function testARecordTheFrameDoesNotCoalesceStillWaitsForItToClose(): void
    {
        // An atomic frame publishes nothing until it closes — that is what makes a
        // rolled-back operation have no history. A type outside `object_types` is not
        // merged with anything, but it is not exempt either: it waits with the rest,
        // and dropping it here loses the record altogether, because nothing afterwards
        // knows it existed.
        $gateway = new InMemoryGateway();
        $transport = new SyncTransport($gateway);
        $buffer = new FrameBuffer(objectTypes: ['order']);
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), frame: $buffer);
        $frame = new AuditFrame($buffer, $writer);

        $frame->begin(atomic: true);

        $writer->writeAll([
            new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]),
            new AuditRecord('invoice', 7, AuditEvent::UPDATE, changes: ['total' => new Change(1, 2)]),
        ]);

        self::assertSame([], $gateway->documents['audit_log'] ?? [], 'the premise: an atomic frame publishes nothing while it is open');

        $frame->end();

        $written = $gateway->documents['audit_log'] ?? [];
        $types = array_column($written, 'objectType');

        sort($types);

        self::assertSame(['invoice', 'order'], $types, 'the record of a type the frame does not coalesce never reached the transport');
    }

    public function testAFrameThatRefusesToGrowTellsTheTransaction(): void
    {
        // coalescing.on_overflow: throw means the operation is refused, and the frame
        // drops what it had. Inside an audit transaction that is exactly the case the
        // transaction exists for: a caller who catches this — "we know this import is
        // too big" — and carries on would commit the rows with no history behind them
        // at all. The commit is refused here, not left to whoever wrote the catch.
        $gateway = new InMemoryGateway();
        $transport = new SyncTransport($gateway);
        $buffer = new FrameBuffer(maxHeld: 1, throwOnOverflow: true);
        $outbox = new OutboxContext();
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), frame: $buffer, outbox: $outbox);

        $outbox->enter();
        $buffer->open(atomic: true);

        try {
            $writer->writeAll([
                new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]),
                new AuditRecord('order', 2, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]),
                new AuditRecord('order', 3, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]),
            ]);

            self::fail('the frame should have refused to grow');
        } catch (FrameOverflowException) {
            // The refusal reaches the caller whatever on_failure says; that much is
            // asserted elsewhere. What this test is about is what it left behind.
        }

        self::assertNotNull($outbox->spoiledBecause(), 'the transaction was not told, so it would have committed rows with no history');
        self::assertStringContainsString('max_held', (string) $outbox->spoiledBecause());
    }

    public function testABatchedRecordGoesToTheOrdinaryTransportEvenWhenItIsSentOneByOne(): void
    {
        // A transport that cannot take a batch is written to record by record, and that
        // is a detail of how it is sent — not a change of which transport it is. The
        // immediate one exists for `immediately: true`, which bypasses the queue to put
        // a record in the index right now; a flush of five thousand records taking that
        // road would make every one of them a synchronous round trip, and inside an
        // audit transaction it would publish them before the commit.
        $ordinary = self::counting();
        $immediate = self::counting();

        $writer = new AuditWriter($ordinary, $immediate, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), failurePolicy: FailurePolicy::Throw);

        $writer->writeManyCompleted([
            (new AuditRecord('order', 1, AuditEvent::UPDATE, actor: 'u', changes: ['status' => new Change('a', 'b')]))
                ->withLoggedAt(new \DateTimeImmutable('2026-09-20 10:00:00'))
                ->withId('a'),
            (new AuditRecord('order', 2, AuditEvent::UPDATE, actor: 'u', changes: ['status' => new Change('a', 'b')]))
                ->withLoggedAt(new \DateTimeImmutable('2026-09-20 10:00:01'))
                ->withId('b'),
        ]);

        self::assertSame(2, $ordinary->sent, 'the batch did not take the ordinary transport');
        self::assertSame(0, $immediate->sent, 'a batch went out through the transport that bypasses the queue');
    }

    /**
     * A transport that counts and cannot take a batch — which is the whole point: the
     * record-by-record fallback is the path under test, and SyncTransport takes batches.
     */
    private static function counting(): TransportInterface
    {
        return new class implements TransportInterface {
            public int $sent = 0;

            public function send(string $index, array $document, ?string $id = null): void
            {
                ++$this->sent;
            }
        };
    }
}
