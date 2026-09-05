<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * What the environment can actually do about Doctrine auditing.
 *
 * Two separate facts, and the difference is the whole point of this class. The
 * listener needs doctrine/orm to read change sets — and it needs **DoctrineBundle**
 * to be attached at all, because it is attached through the `doctrine.event_listener`
 * tag, which is DoctrineBundle's, not the ORM's. With the ORM alone the container
 * boots, the listener is built, the tag is collected by nobody, and not one entity
 * change is ever recorded: exactly the silence `doctrine.enabled: true` exists to
 * refuse. Asking only "is the ORM installed?" could not tell those apart.
 *
 * @internal how ElasticsearchAuditExtension decides; not part of the public API
 */
final class DoctrineSupport
{
    private function __construct(
        public readonly bool $orm,
        public readonly bool $bundle,
    ) {
    }

    /**
     * What is really there. The kernel's bundle list is the reliable answer where it
     * exists — a bundle can be installed and left out of the kernel — and the class
     * being autoloadable is the fallback for a container built without a kernel
     * (which is what the bundle's own unit tests do).
     */
    public static function detect(ContainerBuilder $container): self
    {
        $orm = interface_exists(EntityManagerInterface::class);

        if ($container->hasParameter('kernel.bundles')) {
            /** @var array<string, class-string> $bundles */
            $bundles = (array) $container->getParameter('kernel.bundles');

            return new self($orm, \array_key_exists('DoctrineBundle', $bundles));
        }

        // No kernel.bundles means no kernel: a bare ContainerBuilder, which is a test
        // or a tool, not an application. class_exists() here would answer "registered"
        // for a package that merely sits in vendor/ — the very confusion this class
        // exists to end. A test that needs a different answer injects one: full(),
        // none(), ormOnly().
        return new self($orm, false);
    }

    public static function none(): self
    {
        return new self(false, false);
    }

    /**
     * The ORM without DoctrineBundle: the trap this class exists for.
     */
    public static function ormOnly(): self
    {
        return new self(true, false);
    }

    public static function full(): self
    {
        return new self(true, true);
    }

    public function canAudit(): bool
    {
        return $this->orm && $this->bundle;
    }

    /**
     * What to say when the application asked for entity auditing explicitly.
     */
    public function missing(): string
    {
        if (!$this->orm) {
            return 'doctrine/orm is not installed: composer require doctrine/orm, or drop the option.';
        }

        return 'DoctrineBundle is not registered in the kernel, and the listener is attached through its doctrine.event_listener tag — with doctrine/orm alone nothing would collect the tag and no entity change would be recorded. Install and register doctrine/doctrine-bundle, or drop the option.';
    }
}
