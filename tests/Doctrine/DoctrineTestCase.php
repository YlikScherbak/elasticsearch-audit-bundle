<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * A real EntityManager on an in-memory SQLite database with the audit listener
 * attached — the same wiring the bundle sets up, minus the container.
 */
abstract class DoctrineTestCase extends TestCase
{
    protected EntityManagerInterface $em;
    protected InMemoryGateway $gateway;
    private Configuration $ormConfig;
    private \Doctrine\DBAL\Connection $connection;

    /** @var list<string> messages the writer logged */
    protected array $logs = [];

    /** @var list<string> every statement the connection has run, newest last */
    protected array $queries = [];

    protected function setUp(): void
    {
        if (TestConnection::isSqlite() && !\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the Doctrine tests.');
        }

        // Built by hand rather than through ORMSetup, which insists on symfony/cache.
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../Fixtures']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        // ORM 3 on PHP 8.4 without symfony/var-exporter needs native lazy objects for proxies.
        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        // Every statement the connection runs, so a test can ask how many questions an
        // operation cost. Doctrine's own logging middleware rather than a hand-written
        // one: it is the same class on both DBAL lines this bundle supports, and it
        // logs the transaction boundaries separately from the statements, which is what
        // makes "how many queries" a number rather than a guess.
        $queries = &$this->queries;
        $queries = [];

        $config->setMiddlewares([new LoggingMiddleware(new class($queries) extends AbstractLogger {
            /** @param list<string> $queries */
            public function __construct(private array &$queries)
            {
            }

            /**
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                if (\is_string($context['sql'] ?? null)) {
                    $this->queries[] = $context['sql'];
                }
            }
        })]);

        $this->ormConfig = $config;
        $this->gateway = new InMemoryGateway();

        // With savepoints, which is what the bundle boots on under its default
        // nested_flush_provenance: strict -- DBAL 4 always has them, and on DBAL 3 an
        // application without them is refused. The tests that pin the other mode ask for it.
        $this->connectWatched(savepoints: true, letsGo: false);
        $this->attachListener(FailurePolicy::Log);
    }

    /**
     * Set by a test that runs SQL of its own against a watched row, where the listener's
     * warning that it is not in the history is what the test is about.
     */
    protected bool $unownedStatementsAreExpected = false;

    /**
     * No statement a flush ran goes unclaimed. The listener says so in its log rather than
     * raising it -- the application's own SQL has the persister's shape too -- and a test of an
     * ordinary flush that finds the warning has found a flush that did not claim what it ran.
     */
    protected function tearDown(): void
    {
        if (!$this->unownedStatementsAreExpected) {
            self::assertSame([], array_values(array_filter(
                $this->logs,
                static fn (string $line): bool => str_contains($line, 'so it is not in the history') || str_contains($line, 'may be missing what they did'),
            )), 'a statement of a watched row went unclaimed, or could not be followed');
        }

        parent::tearDown();
    }

    /**
     * What ManagerRegistry::resetManager() does after a failed flush closed the manager:
     * a fresh EntityManager on the same connection, with the same listeners.
     */
    protected function reopen(): void
    {
        $this->em = new EntityManager($this->connection, $this->ormConfig, $this->em->getEventManager());
    }

    /** What the connection did: every connection here is watched, as every audited one is. */
    protected StatementLog $statements;

    /**
     * A fresh connection and manager, watched as setUp() watches them, with a listener told
     * about the log attached -- for a test that wants savepoints, or a log that lets go.
     */
    protected function watchTheConnection(FailurePolicy $policy = FailurePolicy::Log, bool $savepoints = true, bool $letsGo = false): StatementLog
    {
        $this->connectWatched($savepoints, $letsGo);
        $this->attachListener($policy);

        return $this->statements;
    }

    /**
     * The observer goes outside the statement logger, so a statement it ran of its own would
     * be in $queries -- it runs none, and a test says so.
     */
    private function connectWatched(bool $savepoints, bool $letsGo): void
    {
        // Kept whole unless asked: the listener lets go of what it has read, and the tests read
        // the log a second time, on their own, to hold it to the truth.
        $this->statements = new StatementLog($letsGo);

        $middlewares = $this->ormConfig->getMiddlewares();
        $this->ormConfig->setMiddlewares([...$middlewares, ...$this->middlewaresOfTheTest(), new ObservingMiddleware($this->statements)]);

        $this->connection = DriverManager::getConnection(TestConnection::params(), $this->ormConfig);
        $this->ormConfig->setMiddlewares($middlewares);
        TestConnection::reset($this->connection);

        if ($savepoints && method_exists($this->connection, 'setNestTransactionsWithSavepoints')) {
            $this->connection->setNestTransactionsWithSavepoints(true);
        }

        $this->em = new EntityManager($this->connection, $this->ormConfig);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * What a test puts on the connection of its own, beside the observer -- a reading of the
     * rows at every statement, say, which has to be on the same connection to see what a
     * transaction has not committed yet.
     *
     * @return list<\Doctrine\DBAL\Driver\Middleware>
     */
    protected function middlewaresOfTheTest(): array
    {
        return [];
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    protected function attachListener(FailurePolicy $policy, ?ValueComparatorInterface $comparator = null, iterable $enrichers = []): void
    {
        // setUp() already attached one, so attaching replaces rather than adds. Two
        // listeners do not just double every record — the first can answer for the
        // second: the nested-flush tests looked green without the fix because the
        // setUp listener had read the change set before the sabotage, and its record
        // was the one the assertion found.
        $attached = array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $listener) => $listener instanceof AuditSubscriber,
        ));

        foreach ($attached as $previous) {
            $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, $previous);
        }

        $listener = $comparator === null
            ? new AuditSubscriber($this->writer($policy, $enrichers), new AuditMetadataFactory(), $this->statements, skipEmptyUpdates: true, logger: $this->logger())
            : new AuditSubscriber($this->writer($policy, $enrichers), new AuditMetadataFactory(), $this->statements, skipEmptyUpdates: true, comparator: $comparator, logger: $this->logger());
        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, $listener);
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    protected function logger(): LoggerInterface
    {
        $logs = &$this->logs;

        return new class($logs) extends AbstractLogger {
            /** @param list<string> $logs */
            public function __construct(private array &$logs)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->logs[] = strtr((string) $message, [
                    '{reason}' => (string) ($context['reason'] ?? ''),
                    '{entity}' => (string) ($context['entity'] ?? ''),
                    // How many records a warning is about is the part an operator acts
                    // on — "two arrived late" and "two were dropped" are different sizes
                    // of problem — so it is interpolated like the rest.
                    '{count}' => (string) ($context['count'] ?? ''),
                    // What names a row the history does not have -- so that a test can hold
                    // the line to naming it by its shape and not by what it held.
                    '{field}' => (string) ($context['field'] ?? ''),
                    '{class}' => (string) ($context['class'] ?? ''),
                    '{key}' => (string) ($context['key'] ?? ''),
                    '{classes}' => (string) ($context['classes'] ?? ''),
                ]);
            }
        };
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    protected function writer(FailurePolicy $policy, iterable $enrichers = [], ?FrameBuffer $buffer = null): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), $this->actors ?? new ChainActorResolver([], 'tests'), $this->clock ?? new FrozenClock(), $enrichers, $policy, $this->logger(), null, $buffer);
    }

    /**
     * Who the writer asks, and what time it is, when a test needs either to change
     * between one flush and the next. Set before attachListener().
     */
    protected ?ActorResolverInterface $actors = null;

    protected ?ClockInterface $clock = null;

    /**
     * The listener the tests attach, replacing whatever setUp() put there, wired to a
     * frame so a test can hold records across a wider transaction.
     */
    protected function attachListenerWithFrame(FrameBuffer $buffer, FailurePolicy $policy = FailurePolicy::Log): AuditWriter
    {
        $writer = $this->writer($policy, [], $buffer);

        foreach (array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $listener) => $listener instanceof AuditSubscriber,
        ) as $previous) {
            $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, $previous);
        }

        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory(), $this->statements, logger: $this->logger()));

        return $writer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function documents(): array
    {
        return $this->gateway->documents['audit_log'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastDocument(): array
    {
        $documents = $this->documents();

        self::assertNotEmpty($documents, 'No audit document was written.');

        return $documents[array_key_last($documents)];
    }
}
