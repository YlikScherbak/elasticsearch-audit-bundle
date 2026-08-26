<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
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

/**
 * A real EntityManager on an in-memory SQLite database with the audit listener
 * attached — the same wiring the bundle sets up, minus the container.
 */
abstract class DoctrineTestCase extends TestCase
{
    protected EntityManagerInterface $em;
    protected InMemoryGateway $gateway;

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

        $this->em = new EntityManager($connection, $config);
        $this->gateway = new InMemoryGateway();

        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $listener = new AuditSubscriber($this->writer(FailurePolicy::Log), new AuditMetadataFactory(), skipEmptyUpdates: true);
        $this->em->getEventManager()->addEventListener([Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove], $listener);
    }

    protected function writer(FailurePolicy $policy): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), [], $policy);
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
