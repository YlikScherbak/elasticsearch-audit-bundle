<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\OutboxTransport;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The outbox against a real SQL queue on a real connection, because the one thing it
 * promises is about transactions and nothing else can show that.
 */
final class OutboxTransportTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the outbox tests.');
        }

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testARecordQueuedInsideATransactionGoesWithIt(): void
    {
        // The whole point, asked of the real thing: Symfony's Doctrine transport wraps
        // its insert in a transaction of its own, DBAL nests that as a savepoint, and
        // the row is therefore committed - or not - by whoever owns the outer one.
        [$transport, $context] = $this->transport();
        $context->enter();

        $this->connection->beginTransaction();
        $transport->send('audit_log', ['objectType' => 'order'], 'id-1');

        self::assertSame(1, $this->queued(), 'the row is there, uncommitted');

        $this->connection->rollBack();

        self::assertSame(0, $this->queued(), 'and it went with the rollback');

        $this->connection->beginTransaction();
        $transport->send('audit_log', ['objectType' => 'order'], 'id-2');
        $this->connection->commit();

        self::assertSame(1, $this->queued(), 'this one was committed with its change');
    }

    public function testTheQueuedMessageIsTheOneTheWorkerAlreadyKnowsHowToHandle(): void
    {
        $sender = new RememberingSender();
        $transport = new OutboxTransport($sender, $context = new OutboxContext());
        $context->enter();

        $transport->send('audit_log', ['objectType' => 'order'], 'id-1');
        $transport->sendMany([
            ['index' => 'audit_log', 'document' => ['objectType' => 'order'], 'id' => 'id-2'],
            ['index' => 'audit_auth', 'document' => ['objectType' => 'user'], 'id' => 'id-3'],
        ]);

        self::assertInstanceOf(IndexAuditRecord::class, $sender->sent[0]);
        self::assertSame('id-1', $sender->sent[0]->id);

        self::assertInstanceOf(IndexAuditRecords::class, $sender->sent[1]);
        self::assertCount(2, $sender->sent[1]->items, 'a batch travels as one row and becomes one _bulk');
    }

    public function testAnEmptyBatchIsNotARow(): void
    {
        $sender = new RememberingSender();
        $transport = new OutboxTransport($sender, $context = new OutboxContext());
        $context->enter();

        $result = $transport->sendMany([]);

        self::assertSame([], $sender->sent);
        self::assertSame(0, $result->attempted);
    }

    public function testWritingOutsideATransactionIsRefused(): void
    {
        // Turning the outbox on reads as "the history is atomic with the data now", and
        // outside a transaction it is not: the row would be durable whether or not the
        // change it describes ever happened.
        [$transport] = $this->transport();

        $this->expectException(OutboxException::class);
        $this->expectExceptionMessageMatches('/nothing is holding a transaction/');

        $transport->send('audit_log', ['objectType' => 'order'], 'id-1');
    }

    public function testTheWeakerPromiseCanBeAskedForExplicitly(): void
    {
        [$transport] = $this->transport(onlyInsideATransaction: false);

        $transport->send('audit_log', ['objectType' => 'order'], 'id-1');

        self::assertSame(1, $this->queued(), 'kept durably, just not with anything');
    }

    public function testAQueueThatRefusesTheRowSaysSoAndMarksTheTransactionUncommittable(): void
    {
        // The failure that matters most, and the one nothing else catches: under
        // on_failure: log the writer swallows this exception and the business
        // transaction would commit a change whose record never reached the queue. DBAL
        // will not stop it either - the transport's own rollback only undoes its
        // savepoint - so the context is what the transaction asks before committing.
        [$transport, $context] = $this->transport();
        $context->enter();

        $this->connection->executeStatement('DROP TABLE audit_outbox');

        try {
            $transport->send('audit_log', ['objectType' => 'order'], 'id-1');
            self::fail('a missing queue table should have been refused');
        } catch (OutboxException $e) {
            self::assertStringContainsString('migration', $e->getMessage(), 'and says where the table comes from');
            self::assertNotNull($e->getPrevious());
        }

        self::assertNotNull($context->spoiledBecause());
        self::assertStringContainsString('could not be written to the outbox', (string) $context->spoiledBecause());
    }

    /**
     * @return array{OutboxTransport, OutboxContext}
     */
    private function transport(bool $onlyInsideATransaction = true): array
    {
        $queue = new QueueConnection(
            ['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false],
            $this->connection,
        );

        // By hand here, by migration in an application: auto_setup runs DDL, and DDL
        // commits the transaction it is standing in on MySQL.
        $queue->setup();

        $sender = new QueueSender($queue);
        $context = new OutboxContext();

        return [new OutboxTransport($sender, $context, $onlyInsideATransaction), $context];
    }

    private function queued(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_outbox');
    }
}

/**
 * The smallest thing that turns a Doctrine queue connection into a Messenger sender:
 * what FrameworkBundle's own transport does, without the receiving half.
 */
final class QueueSender implements SenderInterface
{
    public function __construct(private readonly QueueConnection $queue)
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        $encoded = (new PhpSerializer())->encode($envelope);
        $this->queue->send($encoded['body'], $encoded['headers'] ?? []);

        return $envelope;
    }
}

final class RememberingSender implements SenderInterface
{
    /** @var list<object> */
    public array $sent = [];

    public function send(Envelope $envelope): Envelope
    {
        $this->sent[] = $envelope->getMessage();

        return $envelope;
    }
}
