<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection\Compiler;

use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Binds the two audit handlers to the bus the configuration names, before Symfony
 * reads their tags.
 *
 * A handler tagged `messenger.message_handler` without a `bus` attribute is available
 * on every bus in the application — a strange shape for a bundle that names one bus
 * and checks it, and one where an audit message dispatched to the wrong bus is handled
 * anyway, so nothing ever says the routing was wrong.
 *
 * It is a pass of its own because of *when* rather than what. MessengerPass reads the
 * tags and builds each bus's handler locator; both bundles register their passes at
 * TYPE_BEFORE_OPTIMIZATION with priority 0, and passes of equal priority run in the
 * order the bundles were registered — FrameworkBundle first, which is the ordinary
 * order. Rewriting the tags after that changed the definitions and nothing else: the
 * locators were already built, every bus already had the handlers, and the test that
 * read the tags back agreed with the code rather than with Messenger. This runs at a
 * higher priority, which is the only way to be ahead of it.
 *
 * What is *checked* about that bus — that it exists, that it is a Messenger bus, that
 * it has middleware which would deliver anything — stays in CarriesRecordsPass, where
 * it reads a container every extension has finished building.
 *
 * @internal
 */
final class ScopeMessengerHandlersPass implements CompilerPassInterface
{
    /**
     * Ahead of FrameworkBundle's MessengerPass, which registers at 0.
     */
    public const PRIORITY = 10;

    public function process(ContainerBuilder $container): void
    {
        // Null with transport: sync (an alias, and no handlers) and with the outbox,
        // which sends to a queue rather than to a bus. Under the outbox the handlers
        // stay on every bus deliberately: which bus carries them is decided by the
        // worker that consumes the queue, and this bundle does not know which that is.
        $bus = ElasticsearchAuditExtension::busBehindTheTransport($container);

        if ($bus === null) {
            return;
        }

        $resolved = self::behindTheAliases($container, $bus);

        // Whether that id is a Messenger bus at all is CarriesRecordsPass's question,
        // and it answers it with a refusal. Here a name nobody tagged simply means the
        // handlers stay where they were: this pass narrows, it does not judge.
        if (!$container->hasDefinition($resolved) || !$container->getDefinition($resolved)->hasTag('messenger.bus')) {
            return;
        }

        foreach ([IndexAuditRecordHandler::class, IndexAuditRecordsHandler::class] as $handler) {
            if (!$container->hasDefinition($handler)) {
                continue;
            }

            $definition = $container->getDefinition($handler);
            $definition->clearTag('messenger.message_handler');
            // The canonical id rather than what the configuration wrote: MessengerPass
            // matches this against the tagged bus service, and messenger.default_bus —
            // the default, and the id everyone writes — is an alias for
            // messenger.bus.default.
            $definition->addTag('messenger.message_handler', ['bus' => $resolved]);
        }
    }

    /**
     * The service an id really names, following aliases to the end. Every id is seen
     * once, so a loop ends at the last id before the circle closes.
     */
    private static function behindTheAliases(ContainerBuilder $container, string $id): string
    {
        $seen = [];

        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }
}
