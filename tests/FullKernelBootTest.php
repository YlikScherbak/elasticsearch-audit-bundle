<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Borsche\ElasticsearchAuditBundle\DependencyInjection\Configuration;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\ElasticsearchAuditBundle;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\Events;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

/**
 * The other half of BundleBootTest, and the half that is easy to mistake for the
 * whole: that one proves every service can be BUILT, in a kernel holding nothing but
 * this bundle. It cannot prove that anything CONSUMES what the bundle declares —
 * `doctrine.event_listener` is DoctrineBundle's tag and `messenger.message_handler`
 * is FrameworkBundle's, and in a kernel without them both are collected by nobody.
 *
 * A container that boots while the listener is attached to no EventManager and the
 * batch message has no handler is exactly the silence this bundle exists to prevent,
 * so it is proven here against the real bundles.
 */
final class FullKernelBootTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/audit-full-kernel-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }

    public function testDoctrineActuallyAttachesTheListenerToItsEventManager(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to boot a Doctrine connection.');
        }

        $kernel = new FullKernel($this->cacheDir);
        $kernel->boot();

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = $kernel->getContainer()->get('doctrine.orm.default_entity_manager');
        $attached = [];

        foreach (AuditSubscriber::EVENTS as $event) {
            foreach ($em->getEventManager()->getListeners($event) as $listener) {
                if ($listener instanceof AuditSubscriber) {
                    $attached[] = $event;
                }
            }
        }

        self::assertSame(AuditSubscriber::EVENTS, $attached, 'every event the listener needs, collected by DoctrineBundle and attached to the manager Doctrine actually uses');

        $kernel->shutdown();
    }

    public function testMessengerActuallyRoutesBothMessagesToTheirHandlers(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to boot a Doctrine connection.');
        }

        $kernel = new FullKernel($this->cacheDir, messenger: true);
        $kernel->boot();

        /** @var HandlersLocatorInterface $handlers */
        $handlers = $kernel->getContainer()->get('test.handlers_locator');

        // Messenger hands the handler over wrapped in a closure, so the descriptor's
        // name is what says whose it is.
        $for = static function (object $message) use ($handlers): array {
            $found = [];

            foreach ($handlers->getHandlers(new Envelope($message)) as $descriptor) {
                $found[] = $descriptor->getName();
            }

            return $found;
        };

        $single = $for(new IndexAuditRecord('audit_log', ['objectType' => 'order']));
        $batch = $for(new IndexAuditRecords([]));

        self::assertCount(1, $single);
        self::assertStringContainsString(IndexAuditRecordHandler::class, $single[0]);
        // The one a worker meets only once a flush is large enough to batch — and the
        // one whose absence sends every record of that flush to the failure transport.
        self::assertCount(1, $batch);
        self::assertStringContainsString(IndexAuditRecordsHandler::class, $batch[0]);

        $kernel->shutdown();
    }

    public function testAConnectionWithoutAnEntityManagerIsRefusedRatherThanListenedTo(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to boot a Doctrine connection.');
        }

        // DoctrineBundle runs a DBAL-only connection perfectly well, and Symfony
        // documents that setup. Attach the listener to one and it hears no flush: the
        // container boots, the tag is collected, the services are all there, and not one
        // entity change is ever recorded. Only the compiler can see this — the extension
        // runs before DoctrineBundle has said which connections have entity managers.
        $kernel = new FullKernel($this->cacheDir, reportingConnection: true);

        try {
            $kernel->boot();
            self::fail('a connection with no entity manager should not have booted');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('no entity manager uses it', self::chainOf($refused));
            self::assertStringContainsString('reporting', self::chainOf($refused));
        }
    }

    public function testABusThatIsNotAMessengerBusIsRefusedRatherThanDispatchedTo(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to boot a Doctrine connection.');
        }

        // A MessageBusInterface that FrameworkBundle did not build carries no
        // messenger.bus tag, so MessengerPass attaches no handler to it. Dispatching
        // then succeeds, returns an Envelope, and delivers the record nowhere — with
        // nothing raised at any point along the way.
        $kernel = new FullKernel($this->cacheDir, messenger: true, ownBus: true);

        try {
            $kernel->boot();
            self::fail('a bus with no handlers should not have booted');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('is not a Messenger bus', self::chainOf($refused));
        }
    }

    private static function chainOf(?\Throwable $e): string
    {
        $said = [];

        for (; $e !== null; $e = $e->getPrevious()) {
            $said[] = $e->getMessage();
        }

        return implode(' | ', $said);
    }
}

/**
 * A kernel with the bundles a real application has: FrameworkBundle for messenger,
 * DoctrineBundle for the entity listener, on an in-memory SQLite connection.
 */
final class FullKernel extends Kernel
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $messenger = false,
        private readonly bool $reportingConnection = false,
        private readonly bool $ownBus = false,
    ) {
        parent::__construct('test'.($messenger ? 'm' : '').($reportingConnection ? 'r' : '').($ownBus ? 'o' : ''), true);
    }

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new ElasticsearchAuditBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $messenger = $this->messenger;
        $reporting = $this->reportingConnection;
        $ownBus = $this->ownBus;

        $loader->load(static function (ContainerBuilder $container) use ($messenger, $reporting, $ownBus): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'messenger' => ['transports' => [], 'routing' => []],
            ]);

            if ($ownBus) {
                // A MessageBusInterface FrameworkBundle did not build: no messenger.bus
                // tag, so MessengerPass attaches no handler to it.
                $container->setDefinition('app.bus', new \Symfony\Component\DependencyInjection\Definition(\Symfony\Component\Messenger\MessageBus::class));
            }

            $container->loadFromExtension('doctrine', [
                // A second connection with no entity manager on it — the DBAL-only
                // setup Symfony documents, and the one an audit listener cannot hear.
                'dbal' => $reporting
                    ? ['default_connection' => 'default', 'connections' => [
                        'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
                        'reporting' => ['driver' => 'pdo_sqlite', 'memory' => true],
                    ]]
                    : ['driver' => 'pdo_sqlite', 'memory' => true],
                'orm' => [
                    // No auto_generate_proxy_classes: DoctrineBundle 3 removed it with
                    // proxies themselves (PHP 8.4 lazy objects), and in 2.x it defaults
                    // to kernel.debug, which this kernel sets anyway.
                    'controller_resolver' => ['auto_mapping' => false],
                    'mappings' => [
                        'Fixtures' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Fixtures',
                            'prefix' => 'Borsche\ElasticsearchAuditBundle\Tests\Fixtures',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);

            $container->loadFromExtension(Configuration::ROOT, [
                'client' => ['hosts' => ['http://localhost:9200']],
                'transport' => $messenger ? 'messenger' : 'sync',
            ] + ($reporting ? ['doctrine' => ['connection' => 'reporting']] : []) + ($ownBus ? ['message_bus' => 'app.bus'] : []));

            // What a test needs to look at: private by default, and the point of the
            // test is to ask the container what a worker would be handed.
            $container->setAlias('test.handlers_locator', 'messenger.bus.default.messenger.handlers_locator')->setPublic(true);
        });
    }

    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    public function getLogDir(): string
    {
        return $this->cacheDir.'/log';
    }
}
