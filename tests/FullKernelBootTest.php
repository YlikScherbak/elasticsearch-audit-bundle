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
}

/**
 * A kernel with the bundles a real application has: FrameworkBundle for messenger,
 * DoctrineBundle for the entity listener, on an in-memory SQLite connection.
 */
final class FullKernel extends Kernel
{
    public function __construct(private readonly string $cacheDir, private readonly bool $messenger = false)
    {
        parent::__construct('test'.($messenger ? 'messenger' : ''), true);
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

        $loader->load(static function (ContainerBuilder $container) use ($messenger): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'messenger' => ['transports' => [], 'routing' => []],
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
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
            ]);

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
