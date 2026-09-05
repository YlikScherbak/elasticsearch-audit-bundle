<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\Compiler\CarriesRecordsPass;
use Borsche\ElasticsearchAuditBundle\DependencyInjection\ElasticsearchAuditExtension;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
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

        $container->setDefinition(ElasticsearchAuditExtension::SERVICE_TRANSPORT, new Definition(\stdClass::class, ['round.and.round']));
        $container->setAlias('round.and.round', 'round.again');
        $container->setAlias('round.again', 'round.and.round');

        try {
            (new CarriesRecordsPass())->process($container);
            self::fail('a bus that is only an alias loop should not have passed');
        } catch (NotConfiguredException $refused) {
            self::assertStringContainsString('is not a Messenger bus', $refused->getMessage());
        }
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
