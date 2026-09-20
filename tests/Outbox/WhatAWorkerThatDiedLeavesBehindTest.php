<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Outbox\AuditTransaction;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\ImmediateTransportGuard;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\OutboxTransport;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The half of the outbox promise that only a dead worker can show.
 *
 * AuditTransactionTest ends where the commit ends: the row is in `audit_outbox` and
 * the operation's own rows are beside it. Everything after that is somebody else's
 * process — a worker picks the message up, writes the document to Elasticsearch, and
 * acknowledges it — and the interesting case is the one where that process does not
 * finish. A worker is killed mid-deploy, the machine loses power, the container is
 * evicted: the document is in the index and the message was never acknowledged.
 *
 * What must happen then is that the message comes back and the write happens again,
 * and that the second write is not a second entry in the history. That is the whole
 * reason every record carries an id of its own: Elasticsearch indexes a document under
 * the id it is given, so a redelivery overwrites itself. Let the id be generated
 * instead — by the cluster, or by a listener that replaced the record and dropped it —
 * and an at-least-once queue quietly turns into duplicated history, which is worse than
 * missing history because nobody counting records can tell.
 *
 * Modelled by letting the visibility window expire rather than by killing a process.
 * What a worker's death leaves behind is a row marked as delivered that nobody is
 * working on any more, and the queue can only tell that from the clock — so the clock
 * is what this moves. Not the redelivery timeout: with a window of zero the row's
 * `delivered_at` and the limit it is compared against land in the same second, and the
 * comparison is strict, so the message would come back or not depending on how fast
 * the machine is.
 */
final class WhatAWorkerThatDiedLeavesBehindTest extends TestCase
{
    private const VISIBILITY_WINDOW = 60;

    private Connection $connection;
    private EntityManagerInterface $em;
    private OutboxContext $context;
    private AuditTransaction $transaction;
    private AuditWriter $writer;
    private InMemoryGateway $gateway;
    private DoctrineTransport $queue;

    protected function setUp(): void
    {
        if (TestConnection::isSqlite() && !\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the outbox tests.');
        }

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $this->connection = DriverManager::getConnection(TestConnection::params(), $config);
        TestConnection::reset($this->connection);
        $this->em = new EntityManager($this->connection, $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $queue = new QueueConnection(
            ['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false, 'redeliver_timeout' => self::VISIBILITY_WINDOW],
            $this->connection,
        );
        $queue->setup();

        $this->queue = new DoctrineTransport($queue, new PhpSerializer());

        $this->context = new OutboxContext();
        $this->gateway = new InMemoryGateway();

        $transport = new OutboxTransport($this->queue, $this->context);
        $immediate = new ImmediateTransportGuard(new SyncTransport($this->gateway), $this->context);

        $buffer = new FrameBuffer();
        $this->writer = new AuditWriter($transport, $immediate, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Log, null, null, $buffer, null, 500, null, $this->context);

        $frame = new AuditFrame($buffer, $this->writer, null, $this->context);
        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($this->writer, new AuditMetadataFactory()));

        $this->transaction = new AuditTransaction($this->connection, $frame, $this->context, null, $this->queue);
    }

    public function testTheRecordIsWaitingInTheQueueAndNowhereElseWhenTheOperationReturns(): void
    {
        // The premise the rest of this file stands on. run() returning means the change
        // and its record are committed together; it does not mean the record is in the
        // index, and an application that reads the log right after the operation is
        // reading a cluster that has not been told yet.
        $this->commitAShipment('SH-1');

        self::assertSame(1, $this->queued(), 'the record is in the queue');
        self::assertSame([], $this->gateway->documents['audit_log'] ?? [], 'and nothing has been written to the cluster yet');
    }

    public function testAWorkerThatDiedBeforeAcknowledgingLeavesTheMessageForTheNextOne(): void
    {
        // The write happened; the ack did not. Nothing in the queue knows the difference
        // between that and a worker still thinking about it, which is why the message
        // has to come back rather than be assumed done.
        $this->commitAShipment('SH-1');

        $first = $this->receive();

        self::assertNotNull($first, 'the premise: there was something to pick up');

        $this->handle($first);

        self::assertCount(1, $this->gateway->documents['audit_log'] ?? [], 'the worker did write the document before it died');

        // and then it died: no ack(), no reject(), nothing. Time passes.
        $this->theVisibilityWindowExpires();

        $second = $this->receive();

        self::assertNotNull($second, 'the message was lost with the worker, so the record would exist only where the worker put it');
        self::assertEquals($first->getMessage(), $second->getMessage(), 'and it is the same record coming back, not a different one');
    }

    public function testTheRedeliveredRecordOverwritesItselfInsteadOfBecomingASecondEntry(): void
    {
        // The reason the id travels with the record. Written again under the same id it
        // is the same document; written under a generated one it is a second entry in
        // the history of a change that happened once — and there is nothing afterwards
        // that can tell the two apart, because they are identical but for the id.
        $this->commitAShipment('SH-1');

        $first = $this->receive();
        self::assertNotNull($first);
        $this->handle($first);

        $documents = $this->gateway->documents['audit_log'];
        $ids = $this->gateway->ids['audit_log'];

        $this->theVisibilityWindowExpires();

        $redelivered = $this->receive();
        self::assertNotNull($redelivered);
        $this->handle($redelivered);
        $this->queue->ack($redelivered);

        self::assertSame($documents, $this->gateway->documents['audit_log'], 'the redelivery wrote a second, different document');
        self::assertSame($ids, $this->gateway->ids['audit_log'], 'the redelivery wrote under a different id, so the history now has the change twice');
        self::assertCount(1, $this->gateway->documents['audit_log']);
        self::assertNotNull($ids[0], 'the record reached the queue without an id, so the cluster would name each delivery itself');
    }

    public function testAnAcknowledgedMessageIsNotHandedOutAgain(): void
    {
        // The other half, and the one that says the test above is measuring something:
        // with the same zero redelivery window, a worker that finishes leaves nothing
        // behind. Without this, "the message came back" would also be true of a queue
        // that simply never removes anything.
        $this->commitAShipment('SH-1');

        $envelope = $this->receive();
        self::assertNotNull($envelope);
        $this->handle($envelope);
        $this->queue->ack($envelope);

        // The same wait that brings a dead worker's message back.
        $this->theVisibilityWindowExpires();

        self::assertNull($this->receive(), 'an acknowledged record was handed out a second time');
        self::assertSame(0, $this->queued());
    }

    public function testAnOperationThatRolledBackLeavesTheWorkerNothingToDo(): void
    {
        // The row and the record went together, so there is no message — and this is the
        // case a worker can never repair, because a record for a change that was undone
        // is not a late write, it is a wrong one.
        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-1'));
                $this->em->flush();

                throw new \DomainException('the operation failed after the audit was queued');
            });

            self::fail('the operation should have failed');
        } catch (\DomainException) {
        }

        self::assertSame(0, $this->queued(), 'a record of a change that was rolled back is waiting for a worker');
        self::assertNull($this->receive());
    }

    private function commitAShipment(string $reference): void
    {
        $this->transaction->run(function () use ($reference): void {
            $shipment = new Shipment($reference);
            $this->em->persist($shipment);
            $this->em->flush();
        });
    }

    /**
     * The clock moving on while nobody is working on the message.
     *
     * A row is "being handled" only in the sense that `delivered_at` is set and recent;
     * the queue has no other way to know, and that is the property the whole redelivery
     * guarantee rests on. Waiting a real minute in a test is not an option, so the row
     * is aged instead of the clock — the same state the queue would be in.
     */
    private function theVisibilityWindowExpires(): void
    {
        $this->connection->executeStatement(
            'UPDATE audit_outbox SET delivered_at = ? WHERE delivered_at IS NOT NULL',
            [new \DateTimeImmutable(sprintf('-%d seconds', self::VISIBILITY_WINDOW * 2))],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    private function receive(): ?Envelope
    {
        $envelopes = iterator_to_array($this->queue->get(), false);

        return $envelopes[0] ?? null;
    }

    /**
     * What a worker does with the message, without the worker: the same handlers the
     * bundle registers, chosen the same way Messenger chooses them.
     */
    private function handle(Envelope $envelope): void
    {
        $message = $envelope->getMessage();

        if ($message instanceof IndexAuditRecords) {
            (new IndexAuditRecordsHandler($this->gateway))($message);

            return;
        }

        self::assertInstanceOf(IndexAuditRecord::class, $message);

        (new IndexAuditRecordHandler($this->gateway))($message);
    }

    private function queued(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_outbox');
    }
}
