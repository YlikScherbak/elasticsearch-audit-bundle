<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection\Compiler;

use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

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

        if (!\in_array($resolved, $buses, true)) {
            throw new NotConfiguredException(sprintf('borsche_elasticsearch_audit.message_bus is "%s", which resolves to "%s" and is not a Messenger bus: the handlers are registered with the messenger.message_handler tag, and Symfony attaches those to the services tagged messenger.bus (%s). Dispatching to anything else succeeds, returns an Envelope, and delivers the record nowhere. Name one of those buses, or set transport to "sync".', $bus, $resolved, $buses === [] ? 'none are configured' : implode(', ', $buses)));
        }

        self::assertTheBusDeliversAnything($container, $bus, $resolved);

        // And the handlers are attached to *this* bus rather than to every bus in the
        // application. Without the attribute Symfony makes a handler available on all of
        // them, which is a strange shape for a bundle that names one bus and checks it:
        // an audit record dispatched to somebody else's bus by mistake would be handled
        // there and written, and nothing would ever say the routing was wrong.
        foreach ([IndexAuditRecordHandler::class, IndexAuditRecordsHandler::class] as $handler) {
            if (!$container->hasDefinition($handler)) {
                continue;
            }

            $definition = $container->getDefinition($handler);
            $definition->clearTag('messenger.message_handler');
            // The canonical id, not what the configuration wrote: MessengerPass matches a
            // handler's bus attribute against the tagged bus service, and
            // messenger.default_bus is an alias for messenger.bus.default.
            $definition->addTag('messenger.message_handler', ['bus' => $resolved]);
        }
    }

    /**
     * Whether the bus has anything that would carry a message out of dispatch().
     *
     * `messenger.bus` says FrameworkBundle built it, and that is not the same as "a
     * message dispatched here reaches a handler": Symfony lets a bus be declared with
     * `default_middleware: false`, and what is left then delivers nothing. Dispatch
     * still succeeds and still answers with an Envelope — the exact failure this pass
     * exists to refuse, one configuration key away.
     *
     * Read fail-open on purpose. The middleware list is FrameworkBundle's own shape, and
     * a bundle that refuses to boot because that shape changed would be a worse bug than
     * the one being caught: anything this cannot read positively is left alone.
     */
    private static function assertTheBusDeliversAnything(ContainerBuilder $container, string $bus, string $resolved): void
    {
        if (!$container->hasDefinition($resolved)) {
            return;
        }

        $middleware = $container->getDefinition($resolved)->getArgument(0);

        if (!$middleware instanceof IteratorArgument) {
            return; // not a shape this knows how to read
        }

        foreach ($middleware->getValues() as $entry) {
            $id = $entry instanceof Reference ? (string) $entry : null;

            // The two that leave the process: send_message hands the envelope to a
            // transport, handle_message calls the handlers. Matched by the end of the id
            // because FrameworkBundle names them per bus ("<bus>.middleware.send_message").
            if ($id !== null && (str_ends_with($id, '.middleware.send_message') || str_ends_with($id, '.middleware.handle_message'))) {
                return;
            }
        }

        throw new NotConfiguredException(sprintf('borsche_elasticsearch_audit.message_bus is "%s"%s, and that bus has neither the send_message nor the handle_message middleware — with default_middleware disabled and nothing equivalent put back, dispatching to it succeeds, answers with an Envelope and delivers the record nowhere. Give the bus Symfony\'s default middleware, or name a bus that has it, or set transport to "sync".', $bus, $resolved === $bus ? '' : ' (which resolves to "'.$resolved.'")'));
    }

    /**
     * The service an id really names, following aliases to the end.
     */
    private static function behindTheAliases(ContainerBuilder $container, string $id): string
    {
        // Every id seen once. A cap would also stop an alias loop, and would stop a long
        // legitimate chain the same way — silently, by answering with the id it happened
        // to be holding. A loop is somebody else's bug and the answer to it is the last
        // id before the circle closes.
        $seen = [];

        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
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
