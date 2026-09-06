<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\DependencyInjection\Compiler;

use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
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
        $this->assertTheOutboxSharesTheConnection($container);
    }

    /**
     * Whether the queue the outbox writes into is on the connection whose entities are
     * being audited.
     *
     * The whole guarantee is one transaction. Two connections to the same database are
     * two transactions, and the failure is silent in the worst way: everything works,
     * every record arrives, and the one thing turning the outbox on was for - the row
     * and its record committing together - quietly does not happen.
     *
     * A Doctrine transport's DSN names the connection rather than a host
     * (doctrine://<connection>), and the factory resolves it through Doctrine's
     * registry, so comparing the two names compares the two services.
     *
     * Read fail-open, like the bus check beside it: a DSN built from an environment
     * variable or a parameter says nothing at compile time, and refusing what cannot be
     * read would refuse the ordinary way of configuring Symfony - which is most
     * production applications, so this check is silent exactly where it is needed most.
     * `audit:check` asks the same question of the resolved services, and that is where
     * an environment-built DSN is finally answerable.
     */
    private function assertTheOutboxSharesTheConnection(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(ElasticsearchAuditExtension::PARAMETER_OUTBOX_QUEUE)) {
            return; // any transport but the outbox
        }

        $queue = $container->getParameter(ElasticsearchAuditExtension::PARAMETER_OUTBOX_QUEUE);
        $expected = $container->getParameter(ElasticsearchAuditExtension::PARAMETER_OUTBOX_CONNECTION);

        if (!\is_string($queue) || !\is_string($expected)) {
            return; // the extension writes strings; anything else was put there by somebody else
        }
        $id = 'messenger.transport.'.$queue;

        if (!$container->hasDefinition($id)) {
            return; // the missing service is its own error, raised where it is resolved
        }

        $dsn = $container->getDefinition($id)->getArgument(0);

        // The shape Symfony's own parameter bag looks for, rather than any per cent
        // sign: a DSN may legitimately contain one - amqp://…/%2f/audit is a vhost -
        // and reading that as "unresolved" would skip the check on exactly the
        // configuration it exists to refuse.
        if (!\is_string($dsn) || preg_match('/%[^%\s]++%/', $dsn) === 1) {
            return; // a placeholder: nothing readable until it is resolved
        }

        if (!str_starts_with($dsn, 'doctrine://')) {
            // The scheme, not the DSN. A DSN carries credentials often enough that
            // repeating one into an exception - which ends up in a deploy log, a CI
            // annotation, an error tracker - is a way of publishing them, and the
            // name and the scheme say everything this message needs to say.
            $scheme = \is_array($parts = parse_url($dsn)) && \is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'an unreadable one';

            throw new NotConfiguredException(sprintf('borsche_elasticsearch_audit.outbox.transport names "%s", whose DSN has the "%s" scheme. The outbox writes its records with one INSERT on the connection the audited entities are on, so that both commit together - which only a Doctrine transport does. Give that transport a doctrine://<connection> DSN, or use transport: messenger, which asks nothing of where the queue lives.', $queue, $scheme));
        }

        $parts = parse_url($dsn);
        $named = \is_array($parts) ? ($parts['host'] ?? '') : '';

        if ($named !== $expected) {
            throw new NotConfiguredException(sprintf('borsche_elasticsearch_audit.outbox.transport writes into "%s", which is on the "%s" Doctrine connection, while the entities being audited are on "%s" (borsche_elasticsearch_audit.doctrine.connection). Two connections are two transactions even against the same database, so the record and the change it describes would commit separately - which is the one thing the outbox exists to prevent. Point them at the same connection.', $queue, $named, $expected));
        }

        $query = [];
        parse_str(\is_array($parts) ? ($parts['query'] ?? '') : '', $query);

        // The DSN is not the whole configuration. Messenger lets a transport carry an
        // options array beside it, and Symfony merges the two as query + options +
        // defaults - so a perfectly ordinary transport that spells auto_setup in
        // options was being refused for a setting it had switched off. Read the same
        // way round, and the same way as Symfony reads it: with FILTER_VALIDATE_BOOL,
        // so "0", "no" and "off" are off here exactly as they are there.
        $options = $container->getDefinition($id)->getArgument(1);
        $options = \is_array($options) ? $options : [];

        $autoSetup = $query['auto_setup'] ?? $options['auto_setup'] ?? true;

        if (filter_var($autoSetup, \FILTER_VALIDATE_BOOL)) {
            throw new NotConfiguredException(sprintf('The outbox queue "%s" has auto_setup on. Creating its table runs DDL, and on MySQL DDL commits the transaction it is standing in - so the first record ever written would commit the operation around it, half-done. Switch auto_setup off, in the DSN or in the transport\'s options, and create the table with a migration.', $queue));
        }
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
        // Asked by class rather than by position. "Defined rather than aliased" told
        // the transports apart while there were two of them; with the outbox there are
        // three, and its first argument is the queue it sends to - read as a bus, that
        // refused every outbox configuration at compile time.
        $bus = ElasticsearchAuditExtension::busBehindTheTransport($container);

        if ($bus === null) {
            return; // nothing is dispatched to a bus, so there is no bus to vouch for
        }
        $buses = array_keys($container->findTaggedServiceIds('messenger.bus'));

        // Through the aliases: messenger.default_bus, the default and the id everyone
        // writes, is an alias for messenger.bus.default, which is what carries the tag.
        $resolved = self::behindTheAliases($container, $bus);

        if (!\in_array($resolved, $buses, true)) {
            throw new NotConfiguredException(sprintf('borsche_elasticsearch_audit.message_bus is "%s", which resolves to "%s" and is not a Messenger bus: the handlers are registered with the messenger.message_handler tag, and Symfony attaches those to the services tagged messenger.bus (%s). Dispatching to anything else succeeds, returns an Envelope, and delivers the record nowhere. Name one of those buses, or set transport to "sync".', $bus, $resolved, $buses === [] ? 'none are configured' : implode(', ', $buses)));
        }

        self::assertTheBusDeliversAnything($container, $bus, $resolved);
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

            // handle_message, and only that one. It is what calls a handler, and every
            // road an audit record can take ends at one: dispatched synchronously it is
            // handled in the request, and routed to a transport it comes back through
            // this same bus in the worker — Messenger consumes on the bus the message was
            // dispatched to. send_message alone proves nothing at all, because
            // SendMessageMiddleware passes an envelope with no sender straight to the
            // next middleware (allow_no_senders is true by default), so a bus with
            // send_message and no routing for these two messages delivers exactly
            // nothing. Matched by the end of the id because FrameworkBundle names the
            // middleware per bus ("<bus>.middleware.handle_message").
            if ($id !== null && str_ends_with($id, '.middleware.handle_message')) {
                return;
            }
        }

        throw new NotConfiguredException(sprintf('borsche_elasticsearch_audit.message_bus is "%s"%s, and that bus has no handle_message middleware — so nothing on it ever calls a handler, in the request or in a worker, and a record dispatched there is answered with an Envelope and delivered nowhere. Give the bus Symfony\'s default middleware, or name a bus that has it, or set transport to "sync".', $bus, $resolved === $bus ? '' : ' (which resolves to "'.$resolved.'")'));
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
        $reference = $arguments[0] ?? null;

        // Read as a reference or not at all. This is DoctrineBundle's own shape — the
        // connection is argument #0 of the entity manager — and casting whatever is
        // there to a string would turn a future change of that shape into a TypeError
        // during compilation instead of "this detection no longer applies".
        if (!$reference instanceof Reference) {
            return null;
        }

        return preg_match('~^doctrine\.dbal\.(.+)_connection$~', (string) $reference, $found) === 1 ? $found[1] : null;
    }
}
