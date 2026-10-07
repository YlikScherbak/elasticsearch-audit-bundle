<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Outbox\AuditTransaction;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Tests\Transport\QueueSender;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\ImmediateTransportGuard;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\OutboxTransport;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * A rollback that fails, and what comes after it.
 *
 * When the database cannot even undo the operation, the transaction is in a state nobody
 * can describe: the next unit of work on the connection would run inside it, and the log
 * of what the connection did would keep it open for ever - every later flush read as one
 * nested in a transaction that will never end, its statements pending until a commit
 * that, when it came, would have committed the abandoned ones with it.
 *
 * So the connection is closed: the session is over, the database discards what it held
 * when the connection drops, and the next use opens a new one. The log is told the same
 * - that session ended, and nothing it left unfinished is used again - which is not the
 * same as being told it rolled back, and the refusal says so too.
 *
 * On a file database: closing an in-memory one would take the database with it.
 */
final class AnAbandonedSessionTest extends TestCase
{
    public static bool $rollBackFails = false;

    private string $file;
    private Connection $connection;
    private EntityManager $em;
    private StatementLog $statements;
    private AuditTransaction $transaction;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the outbox tests.');
        }

        self::$rollBackFails = false;
        $this->file = sys_get_temp_dir().'/audit-abandoned-'.bin2hex(random_bytes(4)).'.sqlite';

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        // The observer outermost, as the bundle puts it: it is told of a rollback only once
        // the driver below it has done one, and here the driver will not.
        $config->setMiddlewares([new RollBackFailsWhenAsked(), new ObservingMiddleware($this->statements = new StatementLog())]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file], $config);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->em = new EntityManager($this->connection, $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $queue = new QueueConnection(['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false], $this->connection);
        $queue->setup();

        $context = new OutboxContext();
        $buffer = new FrameBuffer();
        $writer = new AuditWriter(
            new OutboxTransport(new QueueSender($queue), $context),
            new ImmediateTransportGuard(new SyncTransport(new InMemoryGateway()), $context),
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'system'),
            new FrozenClock(),
            [],
            FailurePolicy::Log,
            null,
            null,
            $buffer,
            null,
            500,
            null,
            $context,
        );

        $frame = new AuditFrame($buffer, $writer, null, $context);
        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory(), $this->statements));
        $this->transaction = new AuditTransaction($this->connection, $frame, $context, statements: $this->statements);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
            @unlink($this->file);
        }
    }

    public function testTheSessionIsEndedAndTheRefusalClaimsNoRollback(): void
    {
        $from = $this->statements->position();
        $voided = $this->statements->voided();

        $refusal = $this->failWithARollbackThatFails();

        // What the abandoned session ran is void in the log - no later commit makes it final,
        // and a reader keeping its place is told, by the count, that it has to read again.
        for ($statement = $from + 1; $statement <= $this->statements->position(); ++$statement) {
            if ($this->statements->statement($statement) !== null) {
                self::assertSame(StatementLog::VOID, $this->statements->fate($statement), 'a statement of the abandoned session is still waiting for its transaction');
            }
        }

        self::assertGreaterThan($voided, $this->statements->voided());

        self::assertStringContainsString('connection was closed', $refusal->getMessage());
        self::assertStringContainsString('reset', $refusal->getMessage(), 'the caller is told the EntityManager is out of step');
        self::assertStringNotContainsString('was rolled back', $refusal->getMessage(), 'a rollback nobody saw happen is not claimed');
        self::assertInstanceOf(\DomainException::class, $refusal->getPrevious(), 'the operation\'s own failure is reachable');

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertFalse($this->statements->inTransaction(), 'the log still holds the abandoned transaction open');
        self::assertSame(0, $this->committedShipments(), 'what the abandoned session wrote is in the database');
    }

    public function testTheNextOperationIsItsOwnAndItsHistoryIsRight(): void
    {
        $this->failWithARollbackThatFails();

        // What the refusal asks for.
        $this->em->clear();

        $this->transaction->run(function (): void {
            $this->em->persist(new Shipment('SH-NEXT'));
            $this->em->flush();
        });

        self::assertSame(1, $this->committedShipments());
        self::assertSame(['SH-NEXT'], $this->queuedReferences(), 'the next operation\'s history holds something of the abandoned one, or misses its own');
        self::assertFalse($this->statements->inTransaction());
    }

    public function testTheNextUnitOfWorkOutsideATransactionIsCommittedAsItRuns(): void
    {
        $this->failWithARollbackThatFails();

        $this->connection->executeStatement("INSERT INTO audit_outbox (body, headers, queue_name, created_at, available_at) VALUES ('x', '[]', 'other', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        self::assertSame(1, (int) $this->other()->fetchOne("SELECT COUNT(*) FROM audit_outbox WHERE queue_name = 'other'"), 'the next write landed inside the abandoned transaction');
    }

    private function failWithARollbackThatFails(): OutboxException
    {
        try {
            $this->transaction->run(function (): void {
                $this->em->persist(new Shipment('SH-ABANDONED'));
                $this->em->flush();
                self::$rollBackFails = true;

                throw new \DomainException('the operation failed');
            });
        } catch (OutboxException $e) {
            return $e;
        } finally {
            self::$rollBackFails = false;
        }

        self::fail('run() did not say that its session was abandoned');
    }

    private function committedShipments(): int
    {
        return (int) $this->other()->fetchOne('SELECT COUNT(*) FROM shipment');
    }

    /** @return list<string> the reference of every shipment record committed to the queue */
    private function queuedReferences(): array
    {
        $references = [];

        foreach ($this->other()->fetchFirstColumn('SELECT body FROM audit_outbox') as $body) {
            $message = (new PhpSerializer())->decode(['body' => $body])->getMessage();
            $documents = $message instanceof IndexAuditRecords ? array_column($message->items, 'document') : [$message instanceof IndexAuditRecord ? $message->document : []];

            foreach ($documents as $document) {
                if (($document['objectType'] ?? null) === 'shipment') {
                    $references[] = $document['changes']['reference']['new'] ?? null;
                }
            }
        }

        return $references;
    }

    private function other(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file]);
    }
}

/**
 * A driver whose rollback fails, when the test says so, without rolling anything back -
 * what a connection that is gone, or a server that refuses, looks like from above.
 */
final class RollBackFailsWhenAsked implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            /**
             * @param array<string, mixed> $params
             */
            public function connect(array $params): DriverConnection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function rollBack(): void
                    {
                        if (AnAbandonedSessionTest::$rollBackFails) {
                            throw new \RuntimeException('the server did not answer the rollback');
                        }

                        parent::rollBack();
                    }
                };
            }
        };
    }
}
