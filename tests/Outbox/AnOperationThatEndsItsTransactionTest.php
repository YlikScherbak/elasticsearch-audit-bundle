<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\OutboxException;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Outbox\AuditTransaction;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\ImmediateTransportGuard;
use Borsche\ElasticsearchAuditBundle\Transport\Outbox\OutboxTransport;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * An operation that ends the transaction run() opened, on a connection the bundle watches.
 *
 * The level cannot see it all: an operation that commits and begins again leaves it at one,
 * and one that rolls back and begins again does too. The driver's own count of commits and
 * rollbacks can. What run() does with that follows from what the frame then describes:
 *
 * - only an extra commit, and the operation finished: everything the frame holds is
 *   committed, or is about to be, so its history is true and is kept - and run() says
 *   afterwards that the change and its history were not committed together;
 * - any rollback: the frame may describe what was undone, so nothing of it is kept;
 * - an extra commit, then a failure: what was committed stays so and has no history, the
 *   rest is undone, and the caller is told both.
 *
 * On a file database, so that a second connection says what is committed.
 */
final class AnOperationThatEndsItsTransactionTest extends TestCase
{
    private string $file;
    private Connection $connection;
    private AuditWriter $writer;
    private AuditTransaction $transaction;

    /** @var (callable(RecordCreatedEvent): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the outbox tests.');
        }

        RollBackFailsWhenAsked::$fails = false;
        $this->file = sys_get_temp_dir().'/audit-ended-'.bin2hex(random_bytes(4)).'.sqlite';

        $configuration = new Configuration();
        $configuration->setMiddlewares([new RollBackFailsWhenAsked(), new ObservingMiddleware($statements = new StatementLog())]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file], $configuration);
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
            /** @param (callable(RecordCreatedEvent): void)|null $listener */
            public function __construct(private &$listener)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof RecordCreatedEvent && $this->listener !== null) {
                    ($this->listener)($event);
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

        $this->transaction = new AuditTransaction($this->connection, new AuditFrame($buffer, $this->writer, null, $context), $context, statements: $statements);
    }

    protected function tearDown(): void
    {
        RollBackFailsWhenAsked::$fails = false;

        if (isset($this->connection)) {
            $this->connection->close();
            @unlink($this->file);
        }
    }

    public function testCommittedAndBegunAgainKeepsTheHistoryAndSaysSo(): void
    {
        $refusal = $this->expectRefusal(function (): void {
            $this->approve(1);
            $this->connection->commit();
            $this->connection->beginTransaction(); // the level is one again
            $this->approve(2);
        });

        self::assertStringContainsString('not together', $refusal->getMessage());
        self::assertNull($refusal->getPrevious(), 'the refusal went through the undo path');
        self::assertSame(2, $this->committedOrders());
        self::assertSame([1, 2], $this->queuedIds(), 'both changes are committed, and the history of both is too');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testCommittedAndLeftClosedKeepsTheHistoryAsWell(): void
    {
        $refusal = $this->expectRefusal(function (): void {
            $this->approve(1);
            $this->connection->commit();
        });

        self::assertStringContainsString('not together', $refusal->getMessage());
        self::assertSame(1, $this->committedOrders());
        self::assertSame([1], $this->queuedIds(), 'the records were queued on their own, each committed as it was written');
    }

    public function testRolledBackAndBegunAgainKeepsNothing(): void
    {
        $refusal = $this->expectRefusal(function (): void {
            $this->approve(1);
            $this->connection->rollBack();
            $this->connection->beginTransaction();
            $this->approve(2);
        }, 'rolled back the transaction');

        self::assertNull($refusal->getPrevious());
        self::assertSame(0, $this->committedOrders(), 'what was left after the operation\'s own rollback is kept');
        self::assertSame([], $this->queuedIds(), 'a history was kept of what the operation undid');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testRolledBackAndLeftClosedKeepsNothing(): void
    {
        $this->expectRefusal(function (): void {
            $this->approve(1);
            $this->connection->rollBack();
        }, 'rolled back the transaction');

        self::assertSame(0, $this->committedOrders());
        self::assertSame([], $this->queuedIds());
    }

    public function testCommittedThenFailedTellsTheCallerBoth(): void
    {
        $ours = new \DomainException('the operation failed after its commit');

        $refusal = $this->expectRefusal(function () use ($ours): void {
            $this->approve(1);
            $this->connection->commit();
            $this->connection->beginTransaction();
            $this->approve(2);

            throw $ours;
        }, 'committed part of its work itself');

        self::assertSame($ours, $refusal->getPrevious());
        self::assertSame(1, $this->committedOrders(), 'what the operation committed is committed, and what came after is not');
        self::assertSame([], $this->queuedIds());
    }

    public function testCommittedThenLeftALevelOpenIsUndoneAfterTheCommitOnly(): void
    {
        $refusal = $this->expectRefusal(function (): void {
            $this->approve(1);
            $this->connection->commit();
            $this->connection->beginTransaction();
            $this->connection->beginTransaction();
            $this->approve(2);
        }, 'committed part of its work itself');

        self::assertInstanceOf(OutboxException::class, $refusal->getPrevious());
        self::assertStringContainsString('did not close', $refusal->getPrevious()->getMessage());
        self::assertSame(1, $this->committedOrders());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testCommittedThenAHistoryRefusedSaysBoth(): void
    {
        // A listener vetoes a record: inside an audit transaction the history is then short,
        // and the commit is refused - but part of the change is committed already.
        $this->listener = static function (RecordCreatedEvent $event): void {
            $event->veto();
        };

        $refusal = $this->expectRefusal(function (): void {
            $this->approve(1);
            $this->connection->commit();
            $this->connection->beginTransaction();
            $this->approve(2);
        }, 'committed part of its work itself');

        self::assertStringContainsString('was not committed', (string) $refusal->getPrevious()?->getMessage());
        self::assertSame(1, $this->committedOrders());
        self::assertSame([], $this->queuedIds());
    }

    public function testCommittedThenAHistoryRefusedThenARollbackThatFailsPromisesNothing(): void
    {
        $this->listener = static function (RecordCreatedEvent $event): void {
            $event->veto();
            RollBackFailsWhenAsked::$fails = true;
        };

        $this->expectRefusal(function (): void {
            $this->approve(1);
            $this->connection->commit();
            $this->connection->beginTransaction();
            $this->approve(2);
        }, 'could not be rolled back');

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testAListenerThatCommitsBetweenTheTwoLooksIsCounted(): void
    {
        // Nothing is wrong when the operation returns; closing the frame runs a listener,
        // and the listener commits. Only the second look can see it.
        $this->listener = function (): void {
            $this->listener = null;
            $this->connection->commit();
            $this->connection->beginTransaction();
        };

        $refusal = $this->expectRefusal(function (): void {
            $this->approve(1);
        });

        self::assertStringContainsString('not together', $refusal->getMessage());
        self::assertSame(1, $this->committedOrders());
        self::assertSame([1], $this->queuedIds());
    }

    public function testAListenerThatRollsBackBetweenTheTwoLooksKeepsNothing(): void
    {
        $this->listener = function (): void {
            $this->listener = null;
            $this->connection->rollBack();
            $this->connection->beginTransaction();
        };

        $this->expectRefusal(function (): void {
            $this->approve(1);
        }, 'rolled back the transaction');

        self::assertSame(0, $this->committedOrders());
        self::assertSame([], $this->queuedIds());
    }

    public function testABalancedOperationIsCommittedWholeAndSaysNothing(): void
    {
        $this->transaction->run(function (): void {
            $this->approve(1);
            $this->connection->beginTransaction();
            $this->approve(2);
            $this->connection->commit();
        });

        // And twice, so the count is read from where it stands and not from zero.
        $this->transaction->run(function (): void {
            $this->approve(3);
        });

        self::assertSame(3, $this->committedOrders());
        self::assertSame([1, 2, 3], $this->queuedIds());
    }

    private function approve(int $id): void
    {
        $this->connection->insert('orders', ['id' => $id, 'status' => 'approved']);
        $this->writer->record('order', $id, 'update', ['status' => new Change('new', 'approved')]);
    }

    /**
     * @param callable(): void $operation
     */
    private function expectRefusal(callable $operation, string $saying = 'committed the transaction'): OutboxException
    {
        try {
            $this->transaction->run($operation);
        } catch (OutboxException $e) {
            self::assertStringContainsString($saying, $e->getMessage());

            return $e;
        }

        self::fail('run() returned as if the change and its history had been committed together');
    }

    private function committedOrders(): int
    {
        return (int) $this->other()->fetchOne('SELECT COUNT(*) FROM orders');
    }

    /** @return list<int|string> the objectId of every record committed to the queue */
    private function queuedIds(): array
    {
        $ids = [];

        foreach ($this->other()->fetchFirstColumn('SELECT body FROM audit_outbox ORDER BY id') as $body) {
            $message = (new PhpSerializer())->decode(['body' => $body])->getMessage();
            $documents = $message instanceof IndexAuditRecords ? array_column($message->items, 'document') : [$message instanceof IndexAuditRecord ? $message->document : []];

            foreach ($documents as $document) {
                $ids[] = $document['objectId'];
            }
        }

        sort($ids);

        return $ids;
    }

    private function other(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file]);
    }
}
