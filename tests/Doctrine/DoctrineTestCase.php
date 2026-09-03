<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
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

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
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

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->ormConfig = $config;
        $this->connection = $connection;

        $this->em = new EntityManager($connection, $config);
        $this->gateway = new InMemoryGateway();

        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->attachListener(FailurePolicy::Log);
    }

    /**
     * What ManagerRegistry::resetManager() does after a failed flush closed the manager:
     * a fresh EntityManager on the same connection, with the same listeners.
     */
    protected function reopen(): void
    {
        $this->em = new EntityManager($this->connection, $this->ormConfig, $this->em->getEventManager());
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
            $this->em->getEventManager()->getListeners(\Doctrine\ORM\Events::postFlush),
            static fn (object $listener) => $listener instanceof AuditSubscriber,
        ));

        foreach ($attached as $previous) {
            $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, $previous);
        }

        $listener = $comparator === null
            ? new AuditSubscriber($this->writer($policy, $enrichers), new AuditMetadataFactory(), skipEmptyUpdates: true, logger: $this->logger())
            : new AuditSubscriber($this->writer($policy, $enrichers), new AuditMetadataFactory(), skipEmptyUpdates: true, comparator: $comparator, logger: $this->logger());
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
                ]);
            }
        };
    }

    /**
     * @param iterable<AuditEnricherInterface> $enrichers
     */
    protected function writer(FailurePolicy $policy, iterable $enrichers = []): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), $enrichers, $policy, $this->logger());
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
