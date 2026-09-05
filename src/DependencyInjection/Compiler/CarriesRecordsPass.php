<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection\Compiler;

use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Refuses a configuration that says auditing works when the path it names cannot carry
 * a record.
 *
 * The extension can only ask what is installed and registered — it runs while the other
 * bundles are still being merged. Two things it cannot see there decide whether anything
 * is ever written:
 *
 * - **which connection has an entity manager.** DoctrineBundle happily runs a
 *   DBAL-only connection, and Symfony documents that setup. Attaching the listener to
 *   one means it hears no flush, because no `EntityManager` uses it — the container
 *   boots, the tag is collected, and not one entity change is recorded;
 * - **whether `message_bus` is a Messenger bus.** The handlers carry FrameworkBundle's
 *   `messenger.message_handler` tag, and MessengerPass attaches such a handler to the
 *   buses tagged `messenger.bus`. Dispatching to a `MessageBusInterface` that is not one
 *   — an application's own, a test double — succeeds, returns an Envelope and delivers
 *   the record nowhere.
 *
 * Both are the failure this bundle exists to refuse: a boot that looks like auditing is
 * on. They are checked here, where the answers exist.
 *
 * @internal
 */
final class CarriesRecordsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->assertTheListenerHearsFlushes($container);
        $this->assertTheBusCarriesHandlers($container);
    }

    private function assertTheListenerHearsFlushes(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER)) {
            return; // doctrine.enabled is false, or the support check already refused
        }

        $tags = $container->getDefinition(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER)->getTag('doctrine.event_listener');
        $connection = (string) ($tags[0]['connection'] ?? 'default');

        // No entity managers at all, or none on this connection. Told apart because the
        // advice differs: one is "you configured doctrine.orm nowhere", the other is
        // "you picked the connection that has no entity manager".
        $managers = $container->hasParameter('doctrine.entity_managers')
            ? $container->getParameter('doctrine.entity_managers')
            : [];
        $managers = \is_array($managers) ? $managers : [];

        foreach ($managers as $service) {
            if (self::managerUses($container, (string) $service, $connection)) {
                return;
            }
        }

        // Nothing here can carry an entity change. Whether that is a failure depends on
        // what was asked for, exactly as it does in the extension: "auto" promised
        // nothing and stays quiet — an application using DBAL alone, which happens to
        // have doctrine/orm in its vendor directory, never asked for entity auditing,
        // and refusing to boot it would be a worse answer than the silence "auto"
        // exists to give. An explicit true is a promise, and a promise nothing can keep
        // has to be said out loud.
        //
        // And staying quiet means leaving the listener where it is. Removing it looked
        // tidier — a service that can never be called — and it is not this pass's to
        // remove: by the time a compiler pass runs, DoctrineBundle has already collected
        // the tag and written the id into the connection's event manager, so the removal
        // left a reference to a service that no longer exists and the container stopped
        // compiling at all. Which version of DoctrineBundle decides whether that happens
        // is not a thing to depend on either. Registered on a connection nothing flushes
        // through, the listener is never called: that is the silence, and it costs a
        // service definition.
        if ($container->getParameter(ElasticsearchAuditExtension::PARAMETER_DOCTRINE_PROMISED) !== true) {
            return;
        }

        throw new NotConfiguredException($managers === []
            ? 'doctrine.enabled is true, and no Doctrine entity manager is configured — the orm section is missing from the Doctrine configuration, so nothing ever calls flush() and no entity change could be recorded. Configure the ORM, or leave doctrine.enabled at "auto" if this application does not audit entities.'
            : sprintf('doctrine.enabled is true and the listener is attached to the Doctrine connection "%s", which no entity manager uses: it would be registered, collected and never called, because a DBAL-only connection has no flush() to listen to. Point borsche_elasticsearch_audit.doctrine.connection at a connection an entity manager uses (%s), or leave doctrine.enabled at "auto".', $connection, implode(', ', array_map(static fn (string $m): string => self::connectionOf($container, $m) ?? '?', array_map('strval', array_values($managers))))));
    }

    private function assertTheBusCarriesHandlers(ContainerBuilder $container): void
    {
        // Defined rather than aliased is what tells the two transports apart: with
        // transport: sync the id is an alias to the service that writes in the request.
        if (!$container->hasDefinition(ElasticsearchAuditExtension::SERVICE_TRANSPORT)) {
            return;
        }

        $bus = (string) $container->getDefinition(ElasticsearchAuditExtension::SERVICE_TRANSPORT)->getArgument(0);
        $buses = array_keys($container->findTaggedServiceIds('messenger.bus'));

        // Through the aliases: messenger.default_bus, the default and the id everyone
        // writes, is an alias for messenger.bus.default, which is what carries the tag.
        $resolved = self::behindTheAliases($container, $bus);

        if (\in_array($resolved, $buses, true)) {
            return;
        }

        throw new NotConfiguredException(sprintf('borsche_elasticsearch_audit.message_bus is "%s", which resolves to "%s" and is not a Messenger bus: the handlers are registered with the messenger.message_handler tag, and Symfony attaches those to the services tagged messenger.bus (%s). Dispatching to anything else succeeds, returns an Envelope, and delivers the record nowhere. Name one of those buses, or set transport to "sync".', $bus, $resolved, $buses === [] ? 'none are configured' : implode(', ', $buses)));
    }

    /**
     * The service an id really names, following aliases to the end.
     */
    private static function behindTheAliases(ContainerBuilder $container, string $id): string
    {
        // Bounded rather than trusting: an alias loop would be somebody else's bug, and
        // this is not the place to hang because of it.
        for ($step = 0; $step < 10 && $container->hasAlias($id); ++$step) {
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }

    private static function managerUses(ContainerBuilder $container, string $manager, string $connection): bool
    {
        return self::connectionOf($container, $manager) === $connection;
    }

    /**
     * The connection an entity manager was built on, read off its first argument —
     * `doctrine.dbal.<name>_connection`, which is how DoctrineBundle names them.
     */
    private static function connectionOf(ContainerBuilder $container, string $manager): ?string
    {
        if (!$container->hasDefinition($manager)) {
            return null;
        }

        $arguments = $container->getDefinition($manager)->getArguments();
        $reference = isset($arguments[0]) ? (string) $arguments[0] : '';

        return preg_match('~^doctrine\.dbal\.(.+)_connection$~', $reference, $found) === 1 ? $found[1] : null;
    }
}
