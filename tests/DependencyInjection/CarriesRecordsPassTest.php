<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\Compiler\CarriesRecordsPass;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\MessengerTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CheckExceptionOnInvalidReferenceBehaviorPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The pass on its own, without a kernel — so what it does to a container is pinned
 * whatever version of DoctrineBundle happens to be installed.
 */
final class CarriesRecordsPassTest extends TestCase
{
    public function testStayingQuietDoesNotLeaveADanglingReferenceBehind(): void
    {
        // How a real container looks by the time any compiler pass runs: DoctrineBundle
        // has collected the doctrine.event_listener tag and written the listener's id
        // into the connection's event manager. Removing the definition here — which is
        // what "auto attaches where it can and stays quiet where it cannot" used to do
        // with a connection that has no entity manager — left that reference pointing at
        // nothing, and the application stopped booting altogether: the loudest possible
        // outcome from the branch whose whole purpose is silence.
        $container = self::containerWithoutAnEntityManager(promised: false);

        (new CarriesRecordsPass())->process($container);
        (new CheckExceptionOnInvalidReferenceBehaviorPass())->process($container);

        self::assertTrue(
            $container->hasDefinition(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER),
            'the listener stays: registered on a connection nothing flushes through, it is simply never called',
        );
    }

    public function testAPromiseNothingCanKeepIsStillRefused(): void
    {
        $container = self::containerWithoutAnEntityManager(promised: true);

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('no Doctrine entity manager');

        (new CarriesRecordsPass())->process($container);
    }

    public function testAnAliasThatPointsAtItselfIsAnAnswerRatherThanAHang(): void
    {
        // A loop is somebody else's bug, and this pass is not the place to find out
        // about it by hanging. It used to be bounded by a step count, which stops a loop
        // and stops a long legitimate chain the same way — silently, answering with
        // whichever id it happened to be holding. Every id is seen once now, so a circle
        // ends at the last id before it closes.
        $container = new ContainerBuilder();
        $container->setParameter(ElasticsearchAuditExtension::PARAMETER_DOCTRINE_PROMISED, false);

        // The messenger transport, because that is the one whose first argument is a
        // bus id - the pass asks by class now, having once read the outbox transport's
        // queue as a bus and refused every outbox configuration there is.
        $container->setDefinition(ElasticsearchAuditExtension::SERVICE_TRANSPORT, new Definition(MessengerTransport::class, ['round.and.round']));
        $container->setAlias('round.and.round', 'round.again');
        $container->setAlias('round.again', 'round.and.round');

        try {
            (new CarriesRecordsPass())->process($container);
            self::fail('a bus that is only an alias loop should not have passed');
        } catch (NotConfiguredException $refused) {
            self::assertStringContainsString('is not a Messenger bus', $refused->getMessage());
        }
    }

    public function testAQueueOnAnotherConnectionIsRefused(): void
    {
        // The failure that is silent in the worst way: everything works, every record
        // arrives, and the one thing the outbox is for - the row and its record in one
        // commit - quietly does not happen. Two connections are two transactions even
        // against the same database, which is why the DSN is compared and not the URL.
        $container = self::outboxContainer('doctrine://reporting?table_name=audit_outbox&auto_setup=false');

        try {
            (new CarriesRecordsPass())->process($container);
            self::fail('a queue on another connection should have been refused');
        } catch (NotConfiguredException $refused) {
            self::assertStringContainsString('Two connections are two transactions', $refused->getMessage());
        }
    }

    public function testAQueueThatIsNotADoctrineTransportIsRefused(): void
    {
        // A record that travels to a broker is not part of anybody's SQL transaction,
        // however durable the broker is.
        $container = self::outboxContainer('amqp://guest:guest@localhost:5672/%2f/audit');

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessageMatches('/only a Doctrine transport does/');

        (new CarriesRecordsPass())->process($container);
    }

    public function testAQueueThatWouldCreateItsOwnTableIsRefused(): void
    {
        // Creating the table is DDL, and on MySQL DDL commits the transaction it is
        // standing in - so the first record ever written would commit half an operation.
        $container = self::outboxContainer('doctrine://default?table_name=audit_outbox');

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessageMatches('/auto_setup on/');

        (new CarriesRecordsPass())->process($container);
    }

    public function testEverySpellingOfOffIsOff(): void
    {
        // Symfony reads it with FILTER_VALIDATE_BOOL, so a check that only knows
        // "false" would refuse a configuration Symfony is perfectly happy with.
        foreach (['false', '0', 'no', 'off'] as $spelling) {
            $container = self::outboxContainer('doctrine://default?table_name=audit_outbox&auto_setup='.$spelling);

            (new CarriesRecordsPass())->process($container);
        }

        self::assertTrue(true, 'none of them was refused');
    }

    public function testADsnNobodyCanReadYetIsLeftAlone(): void
    {
        // The ordinary way of configuring Symfony. Refusing what cannot be read would
        // refuse that; audit:check asks the database instead.
        $container = self::outboxContainer('%env(AUDIT_OUTBOX_DSN)%');

        (new CarriesRecordsPass())->process($container);

        self::assertTrue(true, 'a placeholder says nothing at compile time');
    }

    private static function outboxContainer(string $dsn): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(ElasticsearchAuditExtension::PARAMETER_DOCTRINE_PROMISED, false);
        $container->setParameter(ElasticsearchAuditExtension::PARAMETER_OUTBOX_QUEUE, 'audit_outbox');
        $container->setParameter(ElasticsearchAuditExtension::PARAMETER_OUTBOX_CONNECTION, 'default');

        $container->setDefinition('messenger.transport.audit_outbox', new Definition(\stdClass::class, [$dsn]));

        return $container;
    }

    private static function containerWithoutAnEntityManager(bool $promised): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(ElasticsearchAuditExtension::PARAMETER_DOCTRINE_PROMISED, $promised);

        $listener = new Definition(AuditSubscriber::class);

        foreach (AuditSubscriber::EVENTS as $event) {
            $listener->addTag('doctrine.event_listener', ['event' => $event, 'connection' => 'default']);
        }

        $container->setDefinition(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER, $listener);

        $container->setDefinition('doctrine.dbal.default_connection.event_manager', (new Definition(\stdClass::class))
            ->addArgument([new Reference(ElasticsearchAuditExtension::SERVICE_DOCTRINE_LISTENER)]));

        return $container;
    }
}
