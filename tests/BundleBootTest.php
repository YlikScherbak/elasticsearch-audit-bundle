<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\Configuration;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\ElasticsearchAuditBundle;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\MessengerTransport;
use Borsche\ElasticsearchAuditBundle\Transport\TransportInterface;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CheckTypeDeclarationsPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle in a kernel, which is the only place several things are checked at all:
 * that the extension is the one the bundle hands over, that the configuration tree
 * accepts what the README documents, and that every service the extension defines can
 * actually be built. Loading the tree through a Processor proves none of it — that is
 * how a bundle that could not boot in any application shipped twice.
 */
final class BundleBootTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/audit-bundle-kernel-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }

    public function testAKernelWithTheBundleBoots(): void
    {
        $kernel = new AuditKernel($this->cacheDir);
        $kernel->boot();

        $container = $kernel->getContainer();

        self::assertInstanceOf(AuditWriter::class, $container->get(AuditWriter::class));
        self::assertInstanceOf(AuditReader::class, $container->get(AuditReader::class));
        self::assertInstanceOf(AuditFrame::class, $container->get(AuditFrame::class));

        $kernel->shutdown();
    }

    public function testEveryServiceTheExtensionDefinesCanActuallyBeBuilt(): void
    {
        // Compiling proves the wiring; building proves the types. A service an
        // application never fetches itself — the Doctrine listener is reached through
        // event tags — is otherwise first constructed in production.
        $kernel = new AuditKernel($this->cacheDir);
        $kernel->boot();

        $container = $kernel->getContainer();
        $prefixed = 0;
        $byClass = 0;

        foreach ($container->getServiceIds() as $id) {
            if (str_starts_with($id, Configuration::ROOT.'.')) {
                ++$prefixed;
            } elseif (str_starts_with($id, 'Borsche\\ElasticsearchAuditBundle\\') && $container->has($id)) {
                ++$byClass;
            } else {
                continue;
            }

            self::assertIsObject($container->get($id), $id.' could not be built');
        }

        self::assertGreaterThan(10, $prefixed, 'the whole of the bundle, not a corner of it');
        // The redactor, the two commands, the comparators: defined under their class
        // name, and so invisible to a sweep that only knew the prefixed ids.
        self::assertGreaterThan(3, $byClass, 'the services defined by class id too');

        $kernel->shutdown();
    }

    public function testTheMessengerTransportRefusesAKernelThatCannotConsumeItsHandlers(): void
    {
        // This kernel holds nothing but the bundle, so nothing collects
        // messenger.message_handler — the handlers would exist, the bus would never
        // hear of them, and every record dispatched would fail in a worker. The
        // container refuses to boot rather than promise that. FullKernelBootTest is
        // where the working case is proven, with FrameworkBundle present.
        $kernel = new AuditKernel($this->cacheDir, ['transport' => 'messenger', 'message_bus' => 'test.bus']);

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('FrameworkBundle');

        $kernel->boot();
    }

    public function testTheMessengerTransportWiresBothHandlersWhereTheTagIsCollected(): void
    {
        // Two messages, two handlers: one record and a batch. A worker consuming the
        // batch message finds no handler if only the first is registered, and the
        // records sit in the failure transport with nothing to say why.
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['FrameworkBundle' => 'Symfony\Bundle\FrameworkBundle\FrameworkBundle']);
        $container->register('test.bus', \Symfony\Component\Messenger\MessageBus::class);

        (new ElasticsearchAuditExtension())->load([[
            'client' => ['hosts' => ['http://localhost:9200']],
            'transport' => 'messenger',
            'message_bus' => 'test.bus',
        ]], $container);

        self::assertTrue($container->getDefinition(IndexAuditRecordHandler::class)->hasTag('messenger.message_handler'));
        self::assertTrue($container->getDefinition(IndexAuditRecordsHandler::class)->hasTag('messenger.message_handler'));
        self::assertSame(MessengerTransport::class, $container->getDefinition(ElasticsearchAuditExtension::SERVICE_TRANSPORT)->getClass());
    }

    public function testABundleWhoseAliasIsNotItsUnderscoredNameStillBoots(): void
    {
        // Bundle::getContainerExtension() refuses an alias that is not the underscored
        // bundle name, and a kernel calls it on every boot.
        $extension = (new ElasticsearchAuditBundle())->getContainerExtension();

        self::assertInstanceOf(ElasticsearchAuditExtension::class, $extension);
        self::assertSame(Configuration::ROOT, $extension->getAlias());
    }

    public function testTheSameExtensionInstanceIsHandedOverEveryTime(): void
    {
        $bundle = new ElasticsearchAuditBundle();

        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
    }
}

/**
 * A kernel with nothing in it but this bundle: no FrameworkBundle, so what boots is the
 * bundle's own wiring and nothing else.
 */
final class AuditKernel extends Kernel
{
    /**
     * @param array<string, mixed> $extraConfig
     */
    public function __construct(private readonly string $cacheDir, private readonly array $extraConfig = [])
    {
        parent::__construct('test'.($extraConfig === [] ? '' : 'messenger'), true);
    }

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new ElasticsearchAuditBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $extra = $this->extraConfig;

        $loader->load(static function (ContainerBuilder $container) use ($extra): void {
            $container->register('test.bus', \Symfony\Component\Messenger\MessageBus::class);

            $container->loadFromExtension(Configuration::ROOT, [
                'client' => ['hosts' => ['http://localhost:9200']],
                'indices' => ['default' => 'audit_log', 'routing' => ['auth' => 'audit_auth_log']],
                'reader' => ['max_limit' => 10_000, 'max_result_window' => 50_000],
                'redact' => ['fields' => ['password']],
            ] + $extra);
        });
    }

    /**
     * The check a framework kernel runs in debug: every argument of every definition
     * against the constructor it is passed to. Without it a service nobody fetches is
     * never built, and a type that does not fit waits until an application builds it.
     */
    protected function build(ContainerBuilder $container): void
    {
        // Before removal, not after: a private service nobody in this container refers to
        // — the Doctrine listener, which a real application reaches through event tags —
        // is dropped first and would be checked never.
        $container->addCompilerPass(new CheckTypeDeclarationsPass(true), PassConfig::TYPE_BEFORE_REMOVING);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    // Both naming schemes the extension uses: the prefixed service ids
                    // and the class-id ones (the redactor, the commands, the Messenger
                    // handlers). Only publicising the first left the second built by
                    // nobody, in a test whose whole point is that everything builds.
                    if (str_starts_with($id, Configuration::ROOT.'.') || str_starts_with($id, 'Borsche\\ElasticsearchAuditBundle\\')) {
                        $definition->setPublic(true);
                    }
                }

                // The interface aliases too (TransportInterface, GatewayInterface...):
                // an application autowires them, and this test asks the container for
                // them the same way.
                foreach ($container->getAliases() as $id => $alias) {
                    if (str_starts_with($id, 'Borsche\\ElasticsearchAuditBundle\\')) {
                        $alias->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);
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
