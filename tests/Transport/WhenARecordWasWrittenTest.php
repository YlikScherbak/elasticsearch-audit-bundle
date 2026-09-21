<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Test\AuditCollector;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Transport\WrittenAt;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * The second timestamp, and why one stopped being enough.
 *
 * A record is stamped with the moment its change happened. The flush that sees a
 * change is not always the flush that writes it, and a queue can stand still over a
 * weekend, so "when did this happen" and "when did this reach the cluster" became
 * different questions the moment the first one started being answered honestly.
 *
 * Only the second can say that history is being published late. With one timestamp
 * every record reads as though it arrived the instant it happened, and an outbox
 * nobody consumes looks exactly like an outbox that is keeping up.
 */
final class WhenARecordWasWrittenTest extends TestCase
{
    private const HAPPENED = '2026-09-21 10:00:00';
    private const WRITTEN = '2026-09-22 15:30:00';
    private const RETRIED = '2026-09-22 16:45:00';

    public function testADocumentSaysBothWhenItHappenedAndWhenItWasWritten(): void
    {
        $gateway = new InMemoryGateway();
        $writer = new AuditWriter(
            $transport = new SyncTransport($gateway, self::clockAt(self::WRITTEN)),
            $transport,
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'alice'),
            self::clockAt(self::HAPPENED),
        );

        $writer->record('order', 1, 'updated');

        $document = $gateway->only('audit_log');

        self::assertSame(self::HAPPENED, $document['loggedAt']);
        self::assertSame(self::WRITTEN, $document[WrittenAt::FIELD] ?? null, 'the document cannot say how late it was published');
    }

    public function testTheOnlyThingTheWritePathAddsToARecordIsThatOneField(): void
    {
        // The guard against the next field that seems small enough to add on the way
        // out: what reaches the cluster is the record's own document plus writtenAt and
        // nothing else, on every road out of the bundle, since each builds its own call.
        $document = (new AuditRecord('order', 1, 'updated', new \DateTimeImmutable(self::HAPPENED), 'alice', ['status' => ['old' => 'new', 'new' => 'paid']], ['route' => '/checkout']))
            ->withId('rec-1')
            ->toDocument();

        foreach ($this->everyWayADocumentIsWritten($document) as $road => $written) {
            self::assertSame([WrittenAt::FIELD], self::added($document, $written), sprintf('the %s path changed which fields a document has', $road));
            self::assertSame([], self::added($written, $document), sprintf('the %s path dropped a field of the record', $road));
        }
    }

    public function testTheStampIsTheAttemptThatWroteTheDocumentNotTheOneThatQueuedIt(): void
    {
        // A message carries the document from the request that built it; the handler
        // runs whenever a worker gets to it. Stamping at the first would describe the
        // queue's intentions rather than the index's contents.
        $gateway = new InMemoryGateway();
        $clock = self::movableClock(self::WRITTEN);
        $message = new IndexAuditRecord('audit_log', ['objectType' => 'order', 'objectId' => 1, 'loggedAt' => self::HAPPENED], 'rec-1');

        (new IndexAuditRecordHandler($gateway, $clock))($message);

        self::assertSame(self::WRITTEN, $gateway->only('audit_log')[WrittenAt::FIELD] ?? null);

        // Redelivered after a timeout: the same message, a later attempt, and what is in
        // the index is what this attempt wrote. only() asserts there is exactly one of
        // it, which is the other half of the promise.
        $clock->at = self::RETRIED;

        (new IndexAuditRecordHandler($gateway, $clock))($message);

        self::assertSame(self::RETRIED, $gateway->only('audit_log')[WrittenAt::FIELD] ?? null, 'the document reports the attempt that failed rather than the one that wrote it');
    }

    public function testABatchIsOneAttemptAndSoCarriesOneTimestamp(): void
    {
        // Every item in a _bulk call was sent by the same request. Reading the clock per
        // item would describe how long the loop took and spread one attempt over several
        // timestamps, which reads back as a batch that took a minute to send.
        $gateway = new InMemoryGateway();
        $ticking = new class implements ClockInterface {
            public int $readings = 0;

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(sprintf('2026-09-22 15:30:%02d', $this->readings++), new \DateTimeZone('UTC'));
            }
        };

        (new IndexAuditRecordsHandler($gateway, $ticking))(new IndexAuditRecords([
            ['index' => 'audit_log', 'document' => ['objectType' => 'order', 'objectId' => 1], 'id' => 'rec-1'],
            ['index' => 'audit_log', 'document' => ['objectType' => 'order', 'objectId' => 2], 'id' => 'rec-2'],
            ['index' => 'audit_log', 'document' => ['objectType' => 'order', 'objectId' => 3], 'id' => 'rec-3'],
        ]));

        self::assertSame(
            [self::WRITTEN, self::WRITTEN, self::WRITTEN],
            array_column($gateway->documents['audit_log'], WrittenAt::FIELD),
        );
        self::assertSame(1, $ticking->readings, 'the batch read the clock once per item');
    }

    public function testTheWritePathOwnsTheFieldAndSaysSoAtBothEnds(): void
    {
        // A record cannot be given one — it would be describing a write that has not
        // happened — and a document that arrives carrying one anyway has it replaced.
        // Either half alone leaves a way for a value nobody measured to read as one
        // the bundle did: refusing it on the record is the door a caller uses, and
        // replacing it on the way out is the door an entry read back and re-indexed
        // comes through.
        $written = (new SyncTransport($sync = new InMemoryGateway(), self::clockAt(self::WRITTEN)));
        $written->send('audit_log', ['objectType' => 'order', WrittenAt::FIELD => self::HAPPENED], 'rec-1');

        $collector = new AuditCollector(null, null, self::clockAt(self::WRITTEN));
        $collector->send('audit_log', ['objectType' => 'order', WrittenAt::FIELD => self::HAPPENED], 'rec-1');

        self::assertSame(self::WRITTEN, $collector->written()[0]->document[WrittenAt::FIELD] ?? null, 'the test transport kept a stamp somebody else put there');
        $written->sendMany([['index' => 'audit_batch', 'document' => ['objectType' => 'order', WrittenAt::FIELD => self::HAPPENED], 'id' => 'rec-2']]);

        self::assertSame(self::WRITTEN, $sync->only('audit_log')[WrittenAt::FIELD] ?? null);
        self::assertSame(self::WRITTEN, $sync->only('audit_batch')[WrittenAt::FIELD] ?? null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('set when the document is written');

        new AuditRecord('order', 1, 'updated', null, null, [], [WrittenAt::FIELD => self::WRITTEN]);
    }

    public function testADocumentWrittenBeforeThisFieldExistedReadsAsUnknown(): void
    {
        // Every index out there is full of them, and they are not damaged: the field was
        // not being written when they were. Absent reads as "we do not know", which is
        // what null already means to every caller, and carries no warning — a warning
        // says a document could not be read, and this one could.
        $entry = AuditEntry::fromHit(['_id' => 'rec-1', '_source' => [
            'objectType' => 'order',
            'objectId' => 1,
            'event' => 'updated',
            'loggedAt' => self::HAPPENED,
            'source' => 'alice',
        ]]);

        self::assertNull($entry->attribute(WrittenAt::FIELD));
        self::assertTrue($entry->isComplete(), 'an older document is not a damaged one');
    }

    public function testANewerDocumentReadsBackAndRoundTripsIt(): void
    {
        $entry = AuditEntry::fromHit(['_id' => 'rec-1', '_source' => [
            'objectType' => 'order',
            'objectId' => 1,
            'event' => 'updated',
            'loggedAt' => self::HAPPENED,
            'source' => 'alice',
            WrittenAt::FIELD => self::WRITTEN,
        ]]);

        self::assertSame(self::WRITTEN, $entry->attribute(WrittenAt::FIELD));
        self::assertSame(self::WRITTEN, $entry->toDocument()[WrittenAt::FIELD] ?? null, 'reading an entry and writing it back loses when it was written');
    }

    public function testItIsMappedSoAnOperatorCanAskWhatHasBeenWrittenLately(): void
    {
        // Declared rather than left to `dynamic: false`, which stores a field without
        // indexing it. An unindexed writtenAt reads back fine and cannot be filtered on,
        // and filtering on it is the entire reason it exists.
        self::assertSame(
            ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
            (new IndexDefinition())->properties()[WrittenAt::FIELD] ?? null,
        );

        // And not a base field of AuditRecord, so the generic attribute path takes it.
        // Reserving the name would have made it unfilterable to prevent a clash the
        // mapping refuses anyway.
        $plain = AuditQuery::any();
        $asked = $plain->whereBetween(WrittenAt::FIELD, self::HAPPENED, self::WRITTEN);

        self::assertNotSame($plain->fingerprint(), $asked->fingerprint(), 'the filter was accepted and then changed nothing about the query');
    }

    public function testAnEnricherCannotDeclareItWithAnotherType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base field');

        (new IndexDefinition())->withProperties([WrittenAt::FIELD => ['type' => 'keyword']]);
    }

    /**
     * The same document down each road out of the bundle, keyed by which.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, mixed>>
     */
    private function everyWayADocumentIsWritten(array $document): array
    {
        $clock = self::clockAt(self::WRITTEN);

        $sync = new InMemoryGateway();
        (new SyncTransport($sync, $clock))->send('audit_log', $document, 'rec-1');

        $batch = new InMemoryGateway();
        (new SyncTransport($batch, $clock))->sendMany([['index' => 'audit_log', 'document' => $document, 'id' => 'rec-1']]);

        $queued = new InMemoryGateway();
        (new IndexAuditRecordHandler($queued, $clock))(new IndexAuditRecord('audit_log', $document, 'rec-1'));

        $queuedBatch = new InMemoryGateway();
        (new IndexAuditRecordsHandler($queuedBatch, $clock))(new IndexAuditRecords([['index' => 'audit_log', 'document' => $document, 'id' => 'rec-1']]));

        // The collector is a road too. It is the last step under transport: collector,
        // and it promises a test the document that would have been stored — which it
        // was not, while it was the one road that did not stamp the field.
        $collected = new AuditCollector(null, null, $clock);
        $collected->send('audit_log', $document, 'rec-1');

        $collectedBatch = new AuditCollector(null, null, $clock);
        $collectedBatch->sendMany([['index' => 'audit_log', 'document' => $document, 'id' => 'rec-1']]);

        return [
            'sync' => $sync->only('audit_log'),
            'sync batch' => $batch->only('audit_log'),
            'queued' => $queued->only('audit_log'),
            'queued batch' => $queuedBatch->only('audit_log'),
            'collector' => $collected->written()[0]->document,
            'collector batch' => $collectedBatch->written()[0]->document,
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<string>
     */
    private static function added(array $before, array $after): array
    {
        return array_values(array_diff(array_keys($after), array_keys($before)));
    }

    private static function clockAt(string $moment): ClockInterface
    {
        return new FrozenClock(new \DateTimeImmutable($moment, new \DateTimeZone('UTC')));
    }

    private static function movableClock(string $moment): object
    {
        return new class($moment) implements ClockInterface {
            public function __construct(public string $at)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->at, new \DateTimeZone('UTC'));
            }
        };
    }
}
