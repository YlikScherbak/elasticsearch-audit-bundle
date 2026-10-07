<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Command;

use Borsche\ElasticsearchAuditBundle\Command\CheckCommand;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\AuditsABlob;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every audit declaration on the audited connection, asked before anything is flushed.
 *
 * A flush refuses a declaration it cannot honour through the failure policy - by default a
 * line in a log, written the first time an entity of that class is flushed, which may be in
 * production a week after the deploy. audit:check asks the same questions, the same way and
 * no others, of every class at once, and fails with a code a deployment can stop on.
 */
final class CheckCommandDeclarationsTest extends TestCase
{
    public function testARefusedDeclarationFailsTheCheckAndIsNamed(): void
    {
        $output = $this->check([Shipment::class, ShipmentLine::class, AuditsABlob::class], CheckCommand::FAILURE);

        self::assertStringContainsString(AuditsABlob::class.'::$content is audited, but it is a binary column', $output);
    }

    public function testSoundDeclarationsPass(): void
    {
        $output = $this->check([Shipment::class, ShipmentLine::class]);

        self::assertMatchesRegularExpression('/Declarations: \d+ audited entity class\(es\), every one can be honoured/', $output);
    }

    public function testADeclarationPerInstanceIsNamedAsNotCheckedRatherThanSound(): void
    {
        $output = $this->check([Article::class, Tag::class]);

        self::assertStringContainsString(Article::class, $output);
        self::assertStringContainsString('can only be checked by the flush that meets one', $output);
    }

    public function testAManagerOnAnotherConnectionIsNotAsked(): void
    {
        // Its entities are not audited by this listener, whatever they declare.
        $output = $this->check([AuditsABlob::class], CheckCommand::SUCCESS, managerOnAnotherConnection: true);

        self::assertStringNotContainsString('binary column', $output);
    }

    /**
     * @param list<class-string> $classes
     */
    private function check(array $classes, int $expected = CheckCommand::SUCCESS, bool $managerOnAnotherConnection = false): string
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for an entity manager.');
        }

        $config = new Configuration();
        $config->setMetadataDriverImpl(new class([__DIR__.'/../Fixtures'], $classes) extends AttributeDriver {
            /**
             * @param list<string>       $paths
             * @param list<class-string> $only
             */
            public function __construct(array $paths, private readonly array $only)
            {
                parent::__construct($paths);
            }

            public function getAllClassNames(): array
            {
                return $this->only;
            }
        });
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $statements = new StatementLog();
        $config->setMiddlewares([new ObservingMiddleware($statements)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $audited = $managerOnAnotherConnection ? DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config) : $connection;
        $em = new EntityManager($connection, $config);

        $transport = new SyncTransport(new InMemoryGateway());
        $listener = new AuditSubscriber(new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock()), new AuditMetadataFactory(), $statements);

        $gateway = new InMemoryGateway();
        $gateway->indices['audit_log'] = (new IndexDefinition())->toArray();

        $statements->watchesADriver();
        $command = new CheckCommand(
            $gateway,
            new IndexResolver('audit_log'),
            new IndexDefinition(),
            statements: $statements,
            doctrineConnection: $audited,
            doctrine: new OneManager($em),
            listener: $listener,
        );

        $tester = new CommandTester($command);

        self::assertSame($expected, $tester->execute([]), $tester->getDisplay());

        return $tester->getDisplay();
    }
}

/**
 * The registry DoctrineBundle would give, holding one manager.
 */
final class OneManager implements ManagerRegistry
{
    public function __construct(private readonly ObjectManager $manager)
    {
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->manager;
    }

    public function getManagers(): array
    {
        return ['default' => $this->manager];
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        return $this->manager;
    }

    public function getManagerNames(): array
    {
        return ['default' => 'doctrine.orm.default_entity_manager'];
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        return $this->manager->getRepository($persistentObject);
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        return $this->manager;
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): object
    {
        return new \stdClass();
    }

    public function getConnections(): array
    {
        return [];
    }

    public function getConnectionNames(): array
    {
        return ['default' => 'doctrine.dbal.default_connection'];
    }
}
