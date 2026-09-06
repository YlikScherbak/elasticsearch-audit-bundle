<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Privacy\ChangeRedactor;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Borsche\ElasticsearchAuditBundle\Tests\Transport\RememberingSender;
use Doctrine\ORM\Events;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Borsche\ElasticsearchAuditBundle\Outbox\AuditTransaction;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\Transport\QueueSender;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\ImmediateTransportGuard;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\OutboxTransport;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;

/**
 * The whole promise, end to end and on one connection: the rows a business
 * operation writes and the records describing them are committed together, or
 * neither is.
 *
 * Everything here runs against a real SQLite database with a real Messenger queue
 * table and the real Doctrine listener. Nothing about this can be shown with a fake:
 * what is being tested is what a transaction does.
 */
final class AuditTransactionTest extends TestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private OutboxContext $context;
    private AuditFrame $frame;
    private AuditTransaction $transaction;
    private AuditWriter $writer;
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
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

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($this->connection, $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $queue = new QueueConnection(['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false], $this->connection);
        $queue->setup();   // a migration, in an application

        $this->context = new OutboxContext();
        $this->gateway = new InMemoryGateway();

        $transport = new OutboxTransport(new QueueSender($queue), $this->context);
        $immediate = new ImmediateTransportGuard(new SyncTransport($this->gateway), $this->context);

        $buffer = new FrameBuffer();
        $this->writer = new AuditWriter($transport, $immediate, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Log, null, null, $buffer, null, 500, null, $this->context);

        $this->frame = new AuditFrame($buffer, $this->writer, null, $this->context);
        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($this->writer, new AuditMetadataFactory()));

        $this->transaction = new AuditTransaction($this->connection, $this->frame, $this->context);
    }

    public function testTheRowAndItsRecordAreCommittedTogether(): void
    {
        $this->transaction->run(function (): void {
            $shipment = new Shipment('SH-1');
            $shipment->add(new ShipmentLine('SKU-1', 2));
            $this->em->persist($shipment);
            $this->em->flush();

            $shipment->reference = 'SH-2';
            $this->em->flush();
        });

        self::assertSame(1, $this->shipments(), 'the business row');
        self::assertSame(1, $this->queued(), 'and one record for the whole operation, in the queue');
    }

    public function testARollbackTakesBothHalvesWithIt(): void
    {
        try {
            $this->transaction->run(function (): void {
                $shipment = new Shipment('SH-1');
                $this->em->persist($shipment);
                $this->em->flush();

                $shipment->reference = 'SH-2';
                $this->em->flush();

                throw new \DomainException('the operation failed');
            });
            self::fail('the operation should have failed');
        } catch (\DomainException) {
        }

        self::assertSame(0, $this->shipments(), 'no row');
        self::assertSame(0, $this->queued(), 'and no record of one');
    }

    public function testAQueueThatCannotTakeTheRecordRollsTheChangeBack(): void
    {
        // The insert fails inside the transaction. Under on_failure: log the writer
        // swallows that, and nothing in DBAL would stop the commit — the transport's own
        // rollback undoes its savepoint and leaves the transaction committable. This is
        // the case the context exists for.
        $this->connection->executeStatement('DROP TABLE audit_outbox');

        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-1'));
                $this->em->flush();
            });
            self::fail('a queue that cannot take the record should have failed the operation');
        } catch (OutboxException $e) {
            self::assertStringContainsString('was not committed', $e->getMessage());
        }

        self::assertSame(0, $this->shipments(), 'the change went back with the history that could not be kept');
    }

    public function testAnOperationOfItsOwnCannotOpenASecondTransaction(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->expectException(OutboxException::class);
            $this->expectExceptionMessageMatches('/already open on this connection/');

            $this->transaction->run(static fn (): int => 1);
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testAuditTransactionsDoNotNest(): void
    {
        $this->expectException(OutboxException::class);
        $this->expectExceptionMessageMatches('/already running/');

        $this->transaction->run(function (): void {
            $this->transaction->run(static fn (): int => 1);
        });
    }

    public function testAnImmediateWriteIsRefusedWhileTheTransactionIsOpen(): void
    {
        // It would reach Elasticsearch describing a change that may still roll back, and
        // the index has no transaction to take it back with.
        try {
            $this->transaction->run(function (): void {
                $this->writer->write(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['q' => new Change(1, 2)]), immediately: true);
            });
            self::fail('an immediate write inside the transaction should have been refused');
        } catch (OutboxException $e) {
            // The writer logs the refusal rather than raising it (on_failure: log), so
            // what reaches the caller is the transaction declining to commit - and the
            // reason it declines names the call.
            self::assertStringContainsString('immediately: true', $e->getMessage());
        }

        self::assertSame([], $this->gateway->documents, 'and nothing reached the index');
    }

    public function testTheSameCallIsFineOutsideTheTransaction(): void
    {
        $this->writer->write(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['q' => new Change(1, 2)]), immediately: true);

        self::assertCount(1, $this->gateway->documents['audit_log'], 'immediately: true still means what it always meant');
    }

    public function testTheOperationsResultIsReturned(): void
    {
        $answer = $this->transaction->run(static fn (): string => 'done');

        self::assertSame('done', $answer);
    }

    public function testTheNextTransactionStartsClean(): void
    {
        $this->connection->executeStatement('DROP TABLE audit_outbox');

        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-1'));
                $this->em->flush();
            });
        } catch (OutboxException) {
        }

        // What any caller does after a rolled-back transaction: the manager still holds
        // entities describing rows that no longer exist.
        $this->em->clear();

        // The queue is back, and what spoiled the last operation is not this one's
        // business.
        (new QueueConnection(['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false], $this->connection))->setup();

        $this->transaction->run(function (): void {
            $this->em->persist(new Shipment('SH-2'));
            $this->em->flush();
        });

        self::assertSame(1, $this->shipments());
        self::assertSame(1, $this->queued());
    }

    public function testARefusedRedactionStopsTheCommitEvenThoughItNeverReachesTheQueue(): void
    {
        // The failure the transport cannot see: the record is refused while it is being
        // prepared, so nothing is inserted, nothing rolls back a savepoint, and under
        // on_failure: log nothing reaches the caller either. Without the context this
        // transaction would commit a change whose record was never written.
        $this->rebuildWith(new ChangeRedactor(['secret'], '***', 16, 2));

        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-1'));
                $this->em->flush();

                // Wider than the budget: the redactor refuses the record rather than
                // writing one it could not check.
                $this->writer->record('order', 1, AuditEvent::UPDATE, [
                    'a' => new Change(1, 2),
                    'b' => new Change(1, 2),
                    'c' => new Change(1, 2),
                ]);
            });
            self::fail('a record that could not be redacted should have stopped the commit');
        } catch (OutboxException $e) {
            self::assertStringContainsString('was not committed', $e->getMessage());
        }

        self::assertSame(0, $this->shipments(), 'the change is gone with the record that could not be kept');
    }

    public function testAVetoedRecordStopsTheCommitToo(): void
    {
        // Dropping a record on purpose is a feature everywhere else and a contradiction
        // here: this transaction exists so that every change it makes has a record.
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(RecordCreatedEvent::class, static function (RecordCreatedEvent $event): void {
            $event->veto();
        });

        $this->rebuildWith(null, $dispatcher);

        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-1'));
                $this->em->flush();
            });
            self::fail('a vetoed record should have stopped the commit');
        } catch (OutboxException $e) {
            self::assertStringContainsString('vetoed', $e->getMessage());
        }

        self::assertSame(0, $this->shipments());
    }

    public function testElasticsearchIsNotTouchedWhileTheTransactionRuns(): void
    {
        // The other half of the promise: the cluster being down cannot fail a business
        // operation, because the operation never speaks to it. The queue is local.
        $this->gateway->failWith = new \RuntimeException('the cluster is down');

        $this->transaction->run(function (): void {
            $this->em->persist(new Shipment('SH-1'));
            $this->em->flush();
        });

        self::assertSame(1, $this->shipments(), 'committed while Elasticsearch was unreachable');
        self::assertSame(1, $this->queued(), 'and the record is waiting for it');
    }

    public function testTheQueuedRecordCarriesTheIndexItWasResolvedTo(): void
    {
        // Fixed here rather than when the worker gets to it. Under an alias that rolls
        // over, resolving late would send a redelivery to a different backing index,
        // where the stable id it was written under means nothing.
        $sender = new RememberingSender();
        $this->rebuildWith(null, null, $sender);

        $this->transaction->run(function (): void {
            $this->em->persist(new Shipment('SH-1'));
            $this->em->flush();
        });

        $queued = $sender->sent[0];

        self::assertInstanceOf(IndexAuditRecords::class, $queued);
        self::assertSame('audit_log', $queued->items[0]['index']);
        self::assertNotNull($queued->items[0]['id'], 'and the id a redelivery overwrites itself with');
        self::assertSame(1, $queued->items[0]['document']['objectId'], 'the identifier the database gave it');
    }

    public function testTheSameQueuedRecordDeliveredTwiceIsOneDocument(): void
    {
        // What a worker that died after writing but before acknowledging causes. The id
        // travels with the record, so the second delivery overwrites the first.
        $sender = new RememberingSender();
        $this->rebuildWith(null, null, $sender);

        $this->transaction->run(function (): void {
            $this->em->persist(new Shipment('SH-1'));
            $this->em->flush();
        });

        /** @var IndexAuditRecords $queued */
        $queued = $sender->sent[0];
        $handler = new IndexAuditRecordsHandler($this->gateway);

        $handler($queued);
        $handler($queued);

        self::assertCount(1, $this->gateway->documents['audit_log'], 'delivered twice, written once');
    }

    public function testAFailureBeforeTheTransactionIsNotTheTransactionsProblem(): void
    {
        // The one that broke ordinary use. reportFailure() marks the context for every
        // failed record, and a plain flush outside a transaction fails by design under
        // require_transaction - so the mark was waiting for the next transaction, which
        // then refused to commit an operation that had gone perfectly. In a worker that
        // is every message after the first stray record.
        $this->writer->record('order', 1, AuditEvent::UPDATE, ['q' => new Change(1, 2)]);

        $this->transaction->run(function (): void {
            $this->em->persist(new Shipment('SH-1'));
            $this->em->flush();
        });

        self::assertSame(1, $this->shipments(), 'the operation committed');
        self::assertSame(1, $this->queued(), 'with its record');
        self::assertNull($this->context->spoiledBecause(), 'and nothing is left waiting for the next one');
    }

    public function testARefusedFrameStopsTheCommitEvenWhenTheCallerCatchesIt(): void
    {
        // FrameOverflowException is the one exception a caller has a reason to catch:
        // "this operation is too big, never mind". Catching it left the frame empty,
        // the context clean, and the commit going ahead with no history at all - the
        // exact shape the context exists to catch, reached through the one path that
        // deliberately skips reportFailure().
        $this->rebuildWith(maxHeld: 1);

        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-1'));
                $this->em->flush();

                try {
                    $this->writer->record('order', 1, AuditEvent::UPDATE, ['q' => new Change(1, 2)]);
                    $this->writer->record('order', 2, AuditEvent::UPDATE, ['q' => new Change(1, 2)]);
                } catch (FrameOverflowException) {
                    // "we know"
                }
            });
            self::fail('a refused frame should have stopped the commit');
        } catch (OutboxException $e) {
            self::assertStringContainsString('max_held', $e->getMessage());
        }

        self::assertSame(0, $this->shipments());
        self::assertSame(0, $this->queued());
    }

    public function testAFrameTheOperationLeftOpenStopsTheCommit(): void
    {
        // One end() closes one level. A nested begin() nobody closed meant the frame
        // still held everything, close() wrote nothing, and the commit went ahead with
        // an empty queue - and left the buffer open for whatever ran next.
        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-1'));
                $this->em->flush();

                $this->frame->begin();   // and never ends it
            });
            self::fail('an unclosed frame should have stopped the commit');
        } catch (OutboxException $e) {
            self::assertStringContainsString('did not close it', $e->getMessage());
        }

        self::assertSame(0, $this->shipments());
        self::assertSame(0, $this->queued());
        self::assertFalse($this->frame->isOpen(), 'and nothing of it is left open for the next operation');
    }

    public function testDroppingTheHistoryOnPurposeStopsTheCommitToo(): void
    {
        // reset() at the outermost level is allowed and silent: the frame ends up empty
        // either way, so a commit afterwards is the change with its history deliberately
        // thrown away. Inside a transaction that is a decision the transaction hears.
        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-1'));
                $this->em->flush();

                $this->frame->reset();
            });
            self::fail('a reset inside the transaction should have stopped the commit');
        } catch (OutboxException $e) {
            self::assertStringContainsString('was reset inside the transaction', $e->getMessage());
        }

        self::assertSame(0, $this->shipments());
    }

    /**
     * The same wiring as setUp(), with one piece replaced - the redactor, the event
     * dispatcher or the queue itself.
     */
    private function rebuildWith(?ChangeRedactor $redactor = null, ?EventDispatcher $events = null, ?SenderInterface $sender = null, int $maxHeld = 10000): void
    {
        $queue = new QueueConnection(['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false], $this->connection);

        $transport = new OutboxTransport($sender ?? new QueueSender($queue), $this->context);
        $immediate = new ImmediateTransportGuard(new SyncTransport($this->gateway), $this->context);

        $buffer = new FrameBuffer(maxHeld: $maxHeld);
        $this->writer = new AuditWriter($transport, $immediate, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Log, null, $events, $buffer, $redactor, 500, null, $this->context);

        foreach (array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $listener): bool => $listener instanceof AuditSubscriber,
        ) as $previous) {
            $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, $previous);
        }

        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($this->writer, new AuditMetadataFactory()));
        $this->frame = new AuditFrame($buffer, $this->writer, null, $this->context);
        $this->transaction = new AuditTransaction($this->connection, $this->frame, $this->context);
    }

    private function queued(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_outbox');
    }

    private function shipments(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM Shipment');
    }
}
