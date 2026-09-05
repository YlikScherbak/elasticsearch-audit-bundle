<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * What the environment can actually do about the messenger transport — the same
 * distinction DoctrineSupport makes, for the same reason.
 *
 * The handlers are registered with the `messenger.message_handler` tag, and that tag
 * is **FrameworkBundle's**: with the Messenger component alone nothing collects it,
 * so the container boots, the handlers are built, the bus knows nothing about them,
 * and every record dispatched goes to a bus with no handler. Asking only "is the
 * component installed?" cannot tell that apart from a working setup.
 *
 * @internal how ElasticsearchAuditExtension decides; not part of the public API
 */
final class MessengerSupport
{
    private function __construct(
        public readonly bool $component,
        public readonly bool $bundle,
    ) {
    }

    /**
     * The kernel's bundle list where there is one — a bundle can be installed and left
     * out of the kernel — and "not registered" where there is none: `vendor/` cannot
     * answer this question. A test that needs another answer injects `full()`,
     * `componentOnly()` or `none()`.
     */
    public static function detect(ContainerBuilder $container): self
    {
        $component = interface_exists(MessageBusInterface::class);

        if ($container->hasParameter('kernel.bundles')) {
            /** @var array<string, class-string> $bundles */
            $bundles = (array) $container->getParameter('kernel.bundles');

            return new self($component, \array_key_exists('FrameworkBundle', $bundles));
        }

        // As in DoctrineSupport: without kernel.bundles there is no kernel to have
        // registered anything, and answering from vendor/ would be the same false
        // positive this class was written against.
        return new self($component, false);
    }

    public static function full(): self
    {
        return new self(true, true);
    }

    public static function componentOnly(): self
    {
        return new self(true, false);
    }

    public static function none(): self
    {
        return new self(false, false);
    }

    public function canDispatch(): bool
    {
        return $this->component && $this->bundle;
    }

    /**
     * What to say when the application asked for the messenger transport.
     */
    public function missing(): string
    {
        if (!$this->component) {
            return 'symfony/messenger is not installed: composer require symfony/messenger, or set transport to "sync".';
        }

        return 'FrameworkBundle is not registered in the kernel, and the handlers are registered with its messenger.message_handler tag — with the Messenger component alone nothing would collect the tag, the bus would have no handler for an audit record, and every record dispatched would fail in the worker. Register FrameworkBundle, or set transport to "sync". (Wiring the handlers to a bus of your own is not a third option here: this refusal happens before they are registered, so there would be nothing to wire.)';
    }
}
