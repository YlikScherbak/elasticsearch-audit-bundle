<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Outbox\AuditTransaction;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\ImmediateTransportGuard;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\OutboxTransport;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * An operation that does not leave the transaction as it found it.
 *
 * run() opens one level and closes one. An operation that opened another and forgot it
 * made that one commit() close the innermost level only - on DBAL 4 it released a
 * savepoint - and run() returned, with the change and its history uncommitted and the
 * transaction still open for whatever ran next on the connection. Its undo() closed one
 * level as well, and left the outer one holding the rows of an operation that failed.
 *
 * On a file database, so that a second connection says what is committed rather than
 * what the first can see of its own transaction.
 */
final class AnUnbalancedOperationTest extends TestCase
{
    private string $file;
    private Connection $connection;
    private AuditWriter $writer;
    private AuditTransaction $transaction;

    /** @var (callable(): void)|null what a listener on RecordCreatedEvent does */
    private $listener = null;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the outbox tests.');
        }

        $this->file = sys_get_temp_dir().'/audit-unbalanced-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file]);
        $this->connection->executeStatement('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT)');

        $queue = new QueueConnection(['table_name' => 'audit_outbox', 'queue_name' => 'audit', 'auto_setup' => false], $this->connection);
        $queue->setup();

        $sender = new class($queue) implements SenderInterface {
            public function __construct(private readonly QueueConnection $queue)
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                $encoded = (new PhpSerializer())->encode($envelope);
                $this->queue->send($encoded['body'], $encoded['headers'] ?? []);

                return $envelope;
            }
        };

        $listener = &$this->listener;
        $events = new class($listener) implements EventDispatcherInterface {
            /** @param (callable(): void)|null $listener */
            public function __construct(private &$listener)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof RecordCreatedEvent && $this->listener !== null) {
                    ($this->listener)();
                }

                return $event;
            }
        };

        $context = new OutboxContext();
        $buffer = new FrameBuffer();
        $this->writer = new AuditWriter(
            new OutboxTransport($sender, $context),
            new ImmediateTransportGuard(new SyncTransport(new InMemoryGateway()), $context),
            new IndexResolver('audit_log'),
            new ChainActorResolver([], 'system'),
            new FrozenClock(),
            [],
            FailurePolicy::Log,
            null,
            $events,
            $buffer,
            null,
            500,
            null,
            $context,
        );

        $this->transaction = new AuditTransaction($this->connection, new AuditFrame($buffer, $this->writer, null, $context), $context);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
            @unlink($this->file);
        }
    }

    public function testALevelLeftOpenIsRefusedAndNothingIsKept(): void
    {
        // Found when the operation returns, before the frame is closed: no listener is told
        // of records that are about to be undone.
        $told = 0;
        $this->listener = static function () use (&$told): void {
            ++$told;
        };

        $this->expectRefusal('did not close', function (): void {
            $this->approve(1);
            $this->connection->beginTransaction(); // somebody's service, never closed
            $this->approve(2);
        });

        $this->assertTheConnectionIsAsItWas();
        self::assertSame(['orders' => 0, 'audit_outbox' => 0], $this->committed(), 'run() said nothing was kept, and something was');
        self::assertSame(0, $told, 'listeners were told of records the transaction was about to undo');
    }

    public function testTwoLevelsLeftOpenAreBothClosed(): void
    {
        $this->expectRefusal('did not close', function (): void {
            $this->approve(1);
            $this->connection->beginTransaction();
            $this->connection->beginTransaction();
            $this->approve(2);
        });

        $this->assertTheConnectionIsAsItWas();
        self::assertSame(['orders' => 0, 'audit_outbox' => 0], $this->committed());
    }

    public function testAFailingOperationThatLeftALevelOpenIsUndoneWhole(): void
    {
        try {
            $this->transaction->run(function (): void {
                $this->approve(1);
                $this->connection->beginTransaction();
                $this->approve(2);

                throw new \RuntimeException('the operation failed');
            });
            self::fail('run() returned');
        } catch (\RuntimeException $e) {
            self::assertSame('the operation failed', $e->getMessage(), 'the operation\'s own failure is the one the caller gets');
        }

        $this->assertTheConnectionIsAsItWas();
        self::assertSame(['orders' => 0, 'audit_outbox' => 0], $this->committed());
    }

    public function testAnOperationThatEndedTheTransactionItselfIsToldSoWithoutAGuess(): void
    {
        $refusal = $this->expectRefusal('ended the audit transaction itself', function (): void {
            $this->approve(1);
            $this->connection->commit(); // the transaction run() opened
        });

        // What the operation committed stays committed - nothing here can undo it - and the
        // refusal says that much without claiming either way what happened.
        self::assertStringNotContainsString('rolled back', $refusal->getMessage());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, $this->committed()['orders']);
    }

    public function testAListenerThatOpensATransactionWhileTheHistoryIsWrittenIsCaught(): void
    {
        // Between the operation and the commit the frame is closed, and closing it runs
        // the application's listeners: a level opened there was not there when the
        // operation returned.
        $this->listener = function (): void {
            $this->connection->beginTransaction();
        };

        $this->expectRefusal('did not close', function (): void {
            $this->approve(1);
        });

        $this->assertTheConnectionIsAsItWas();
        self::assertSame(['orders' => 0, 'audit_outbox' => 0], $this->committed());
    }

    public function testAListenerThatCommitsWhileTheHistoryIsWrittenIsCaught(): void
    {
        $this->listener = function (): void {
            $this->listener = null; // once
            $this->connection->commit();
        };

        $refusal = $this->expectRefusal('ended the audit transaction itself', function (): void {
            $this->approve(1);
        });

        self::assertStringNotContainsString('rolled back', $refusal->getMessage());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testABalancedOperationIsCommittedWhole(): void
    {
        // The control: nesting the operation closes is nesting it may have.
        $this->transaction->run(function (): void {
            $this->approve(1);
            $this->connection->beginTransaction();
            $this->approve(2);
            $this->connection->commit();
        });

        // Both records in one message: the frame hands what it held over as one batch.
        self::assertSame(['orders' => 2, 'audit_outbox' => 1], $this->committed());
    }

    private function approve(int $id): void
    {
        $this->connection->insert('orders', ['id' => $id, 'status' => 'approved']);
        $this->writer->record('order', $id, 'update', ['status' => new Change('new', 'approved')]);
    }

    /**
     * @param callable(): void $operation
     */
    private function expectRefusal(string $saying, callable $operation): OutboxException
    {
        try {
            $this->transaction->run($operation);
        } catch (OutboxException $e) {
            self::assertStringContainsString($saying, $e->getMessage());

            return $e;
        }

        self::fail('run() returned as if the operation had been committed whole');
    }

    private function assertTheConnectionIsAsItWas(): void
    {
        self::assertSame(0, $this->connection->getTransactionNestingLevel(), 'a level was left open for whatever runs next');

        // And the next unit of work is its own: not inside what was left behind.
        $this->connection->insert('orders', ['id' => 99, 'status' => 'next request']);
        self::assertSame(1, (int) $this->other()->fetchOne('SELECT COUNT(*) FROM orders WHERE id = 99'), 'the next write landed inside a transaction nobody will commit');
        $this->connection->delete('orders', ['id' => 99]);
    }

    /** @return array{orders: int, audit_outbox: int} */
    private function committed(): array
    {
        $other = $this->other();

        return [
            'orders' => (int) $other->fetchOne('SELECT COUNT(*) FROM orders'),
            'audit_outbox' => (int) $other->fetchOne('SELECT COUNT(*) FROM audit_outbox'),
        ];
    }

    private function other(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file]);
    }
}
