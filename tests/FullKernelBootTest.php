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
        // Without DoctrineBundle: this is a question about the bus, and tying it to a
        // database driver being compiled in is how a guard stops running.
        $kernel = new FullKernel($this->cacheDir, messenger: true, withoutDoctrine: true);
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

    public function testAnApplicationThatNeverAskedForEntityAuditingStillBoots(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to boot a Doctrine connection.');
        }

        // doctrine.enabled defaults to "auto", which promises nothing: it attaches the
        // listener where it can and stays quiet where it cannot. A DBAL-only application
        // that happens to have doctrine/orm in its vendor directory asked for no entity
        // auditing at all, and refusing to boot it is a worse answer than the silence
        // "auto" exists to give. Only an explicit "true" is a promise worth failing over.
        $kernel = new FullKernel($this->cacheDir, dbalOnly: true);
        $kernel->boot();

        // What the assertion is: it booted. The listener service is private, so asking
        // the container whether it exists answers "no" either way and would prove
        // nothing — while the writer, which is what an application without entity
        // auditing still uses, has to be there and working.
        self::assertInstanceOf(
            \Borsche\ElasticsearchAuditBundle\Writer\AuditWriter::class,
            $kernel->getContainer()->get(\Borsche\ElasticsearchAuditBundle\Writer\AuditWriter::class),
        );

        $kernel->shutdown();
    }

    public function testAnExplicitPromiseOfEntityAuditingIsStillKept(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to boot a Doctrine connection.');
        }

        // The other half. doctrine.enabled: true says "audit my entities", and a
        // configuration with nothing to keep that promise has to say so rather than boot
        // into silence.
        $kernel = new FullKernel($this->cacheDir, dbalOnly: true, insistOnDoctrine: true);

        try {
            $kernel->boot();
            self::fail('an explicit promise with nothing to keep it should not have booted');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('no Doctrine entity manager', self::chainOf($refused));
        }
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
        $kernel = new FullKernel($this->cacheDir, reportingConnection: true, insistOnDoctrine: true);

        try {
            $kernel->boot();
            self::fail('a connection with no entity manager should not have booted');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('which no entity manager uses', self::chainOf($refused));
            self::assertStringContainsString('reporting', self::chainOf($refused));
        }
    }

    public function testABusThatIsNotAMessengerBusIsRefusedRatherThanDispatchedTo(): void
    {
        // A MessageBusInterface that FrameworkBundle did not build carries no
        // messenger.bus tag, so MessengerPass attaches no handler to it. Dispatching
        // then succeeds, returns an Envelope, and delivers the record nowhere — with
        // nothing raised at any point along the way. No Doctrine here either: the
        // refusal is about the bus.
        $kernel = new FullKernel($this->cacheDir, messenger: true, ownBus: true, withoutDoctrine: true);

        try {
            $kernel->boot();
            self::fail('a bus with no handlers should not have booted');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('is not a Messenger bus', self::chainOf($refused));
        }
    }

    public function testABusThatWouldDeliverNothingIsRefusedRatherThanDispatchedTo(): void
    {
        // The tag says FrameworkBundle built the bus. It does not say a message
        // dispatched there reaches anything: Symfony lets a bus be declared with
        // default_middleware disabled, and what is left carries nothing. dispatch()
        // still succeeds and still answers with an Envelope — the failure this pass
        // exists to refuse, one configuration key away from the shape it already caught.
        $kernel = new FullKernel($this->cacheDir, messenger: true, withoutDoctrine: true, busWithoutDelivery: true);

        try {
            $kernel->boot();
            self::fail('a bus with no delivery middleware should not have booted');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('neither the send_message nor the handle_message middleware', self::chainOf($refused));
        }
    }

    public function testTheHandlersAreAttachedToTheConfiguredBusAndNotToEveryBus(): void
    {
        // A handler tagged without a bus is available on all of them, which is a strange
        // shape for a bundle that names one bus and checks it: an audit record dispatched
        // to somebody else's bus by mistake would be handled there and written, and
        // nothing would ever say the routing was wrong.
        $kernel = new FullKernel($this->cacheDir, messenger: true, withoutDoctrine: true);
        $kernel->boot();

        $tags = $kernel->getContainer()->getParameter('test.handler_buses');

        self::assertSame(
            ['messenger.bus.default', 'messenger.bus.default'],
            $tags,
            'both handlers, bound to the canonical id of the configured bus',
        );

        $kernel->shutdown();
    }

    public function testANamedEntityManagerOnANamedConnectionBootsFine(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to boot a Doctrine connection.');
        }

        // The positive side of the connection check, and the one that pins what it reads:
        // connectionOf() takes the connection from the entity manager's own definition,
        // which is DoctrineBundle's internal shape. A second database with its own
        // manager is the ordinary way an application has one, and the refusal must not
        // catch it.
        $kernel = new FullKernel($this->cacheDir, insistOnDoctrine: true, namedEntityManager: true);
        $kernel->boot();

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = $kernel->getContainer()->get('doctrine.orm.audit_entity_manager');
        $attached = [];

        foreach ($em->getEventManager()->getListeners(Events::postFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                $attached[] = $listener::class;
            }
        }

        self::assertSame([AuditSubscriber::class], $attached, 'attached to the manager on the connection it was pointed at');

        $kernel->shutdown();
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
        private readonly bool $dbalOnly = false,
        private readonly bool $insistOnDoctrine = false,
        private readonly bool $withoutDoctrine = false,
        private readonly bool $busWithoutDelivery = false,
        private readonly bool $namedEntityManager = false,
    ) {
        parent::__construct('test'.($messenger ? 'm' : '').($reportingConnection ? 'r' : '').($ownBus ? 'o' : '').($dbalOnly ? 'd' : '').($insistOnDoctrine ? 'i' : '').($withoutDoctrine ? 'n' : '').($busWithoutDelivery ? 'b' : '').($namedEntityManager ? 'e' : ''), true);
    }

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();

        // Left out for the questions that are about Messenger alone. DoctrineBundle
        // needs a driver to boot a connection, and on a PHP without pdo_sqlite the whole
        // test was skipped — so the boot guards around the bus, which have nothing to do
        // with Doctrine, could not run at all on the very environment where a missing
        // extension makes an unbootable container likeliest.
        if (!$this->withoutDoctrine) {
            yield new DoctrineBundle();
        }

        yield new ElasticsearchAuditBundle();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function fixtureMapping(): array
    {
        return ['Fixtures' => [
            'type' => 'attribute',
            'dir' => __DIR__.'/Fixtures',
            'prefix' => 'Borsche\\ElasticsearchAuditBundle\\Tests\\Fixtures',
            'is_bundle' => false,
        ]];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $messenger = $this->messenger;
        $reporting = $this->reportingConnection;
        $ownBus = $this->ownBus;
        $dbalOnly = $this->dbalOnly;
        $insist = $this->insistOnDoctrine;
        $noDoctrine = $this->withoutDoctrine;
        $undelivered = $this->busWithoutDelivery;
        $namedManager = $this->namedEntityManager;

        $loader->load(static function (ContainerBuilder $container) use ($messenger, $reporting, $ownBus, $dbalOnly, $insist, $noDoctrine, $undelivered, $namedManager): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'messenger' => ['transports' => [], 'routing' => []],
            ]);

            if ($undelivered) {
                // A bus with the messenger.bus tag and nothing that would take a message
                // anywhere — what `default_middleware: false` produces, built here
                // instead of through the configuration tree because the spelling of that
                // key differs between the Symfony versions this bundle supports and the
                // shape being tested does not.
                $container->setDefinition('audit.bus', (new \Symfony\Component\DependencyInjection\Definition(\Symfony\Component\Messenger\MessageBus::class, [
                    new \Symfony\Component\DependencyInjection\Argument\IteratorArgument([]),
                ]))->addTag('messenger.bus'));
            }

            if ($ownBus) {
                // A MessageBusInterface FrameworkBundle did not build: no messenger.bus
                // tag, so MessengerPass attaches no handler to it.
                $container->setDefinition('app.bus', new \Symfony\Component\DependencyInjection\Definition(\Symfony\Component\Messenger\MessageBus::class));
            }

            if (!$noDoctrine) {
                $container->loadFromExtension('doctrine', $dbalOnly ? [
                // No orm section at all, so DoctrineBundle registers no entity manager —
                // the shape an application that uses DBAL alone has, and one where
                // nothing about entity auditing was ever asked for.
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            ] : [
                // A second connection with no entity manager on it — the DBAL-only
                // setup Symfony documents, and the one an audit listener cannot hear.
                'dbal' => ($reporting || $namedManager)
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
                ] + ($namedManager ? [
                    // A named entity manager on a named connection: the shape a second
                    // database has in a real application, and the one connectionOf()
                    // reads off the manager's own definition.
                    'default_entity_manager' => 'default',
                    'entity_managers' => [
                        'default' => ['connection' => 'default', 'mappings' => self::fixtureMapping()],
                        'audit' => ['connection' => 'reporting', 'mappings' => self::fixtureMapping()],
                    ],
                ] : [
                    'mappings' => [
                        'Fixtures' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Fixtures',
                            'prefix' => 'Borsche\ElasticsearchAuditBundle\Tests\Fixtures',
                            'is_bundle' => false,
                        ],
                    ],
                ]),
                ]);
            }

            $container->loadFromExtension(Configuration::ROOT, [
                'client' => ['hosts' => ['http://localhost:9200']],
                'transport' => $messenger ? 'messenger' : 'sync',
                'doctrine' => (($reporting || $namedManager) ? ['connection' => 'reporting'] : []) + ($insist ? ['enabled' => true] : []),
            ] + ($ownBus ? ['message_bus' => 'app.bus'] : []) + ($undelivered ? ['message_bus' => 'audit.bus'] : []));

            // What a test needs to look at: private by default, and the point of the
            // test is to ask the container what a worker would be handed.
            $container->setAlias('test.handlers_locator', 'messenger.bus.default.messenger.handlers_locator')->setPublic(true);

            // And which bus each handler was bound to, read after every pass has run.
            $container->addCompilerPass(new HandlerBusesPass(), \Symfony\Component\DependencyInjection\Compiler\PassConfig::TYPE_BEFORE_REMOVING, -1000);
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

/**
 * Records which bus each audit handler ended up tagged for, so a test can read it back
 * from the built container.
 */
final class HandlerBusesPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $buses = [];

        foreach ([IndexAuditRecordHandler::class, IndexAuditRecordsHandler::class] as $handler) {
            if (!$container->hasDefinition($handler)) {
                continue;
            }

            foreach ($container->getDefinition($handler)->getTag('messenger.message_handler') as $tag) {
                $buses[] = $tag['bus'] ?? '(every bus)';
            }
        }

        $container->setParameter('test.handler_buses', $buses);
    }
}
