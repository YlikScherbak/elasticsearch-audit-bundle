<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle;

use Borsche\ElasticsearchAuditBundle\DependencyInjection\Compiler\CarriesRecordsPass;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ElasticsearchAuditBundle extends Bundle
{
    private ?ElasticsearchAuditExtension $auditExtension = null;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // After every extension has been loaded: what this asks — which connection has
        // an entity manager, whether the configured bus is a Messenger bus — are answers
        // only DoctrineBundle and FrameworkBundle can give, and they give them here.
        $container->addCompilerPass(new CarriesRecordsPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * The configuration key is vendor-prefixed — borsche_elasticsearch_audit — and the
     * convention Bundle enforces expects the bundle's own name underscored, so it has to
     * be stepped around rather than obeyed: renaming the alias would rename the key in
     * every application that already configured the bundle.
     *
     * The instance is held here rather than in the parent's $extension, whose visibility
     * differs across the Symfony majors this bundle supports.
     */
    public function getContainerExtension(): ExtensionInterface
    {
        return $this->auditExtension ??= new ElasticsearchAuditExtension();
    }
}
