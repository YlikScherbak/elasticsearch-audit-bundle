<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
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
        $this->writer = new AuditWriter($transport, $immediate, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Log, null, null, $buffer);

        $frame = new AuditFrame($buffer, $this->writer);
        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($this->writer, new AuditMetadataFactory()));

        $this->transaction = new AuditTransaction($this->connection, $frame, $this->context);
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

    private function queued(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_outbox');
    }

    private function shipments(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM Shipment');
    }
}
