<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\Configuration;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\ElasticsearchAuditBundle;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
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
        $built = 0;

        foreach ($container->getServiceIds() as $id) {
            if (!str_starts_with($id, Configuration::ROOT.'.')) {
                continue;
            }

            self::assertIsObject($container->get($id), $id.' could not be built');
            ++$built;
        }

        self::assertGreaterThan(10, $built, 'the whole of the bundle, not a corner of it');

        $kernel->shutdown();
    }

    public function testTheBundleHandsOverTheVendorPrefixedExtension(): void
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
    public function __construct(private readonly string $cacheDir)
    {
        parent::__construct('test', true);
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
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension(Configuration::ROOT, [
                'client' => ['hosts' => ['http://localhost:9200']],
                'indices' => ['default' => 'audit_log', 'routing' => ['auth' => 'audit_auth_log']],
                'reader' => ['max_limit' => 10_000, 'max_result_window' => 50_000],
                'redact' => ['fields' => ['password']],
            ]);
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
                    if (str_starts_with($id, Configuration::ROOT.'.')) {
                        $definition->setPublic(true);
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
