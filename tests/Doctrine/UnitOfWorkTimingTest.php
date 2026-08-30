<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * What the unit of work knows, and when. Tracking changes inside the elements of a
 * collection rests on both answers, and on Doctrine's behaviour rather than on
 * reasoning about it — so they are pinned here, for both supported ORM majors.
 */
final class UnitOfWorkTimingTest extends DoctrineTestCase
{
    /** @var list<array{owner: string, scheduled: list<string>}> */
    private array $seen = [];

    public function testScheduledUpdatesAreStillThereWhenPostUpdateRuns(): void
    {
        $shipment = $this->shipmentWithTwoLines();
        $this->watchPostUpdate();

        $shipment->reference = 'SH-2';                 // the owner is dirty...
        $shipment->lines->first()->quantity = 7;       // ...and so is one of its lines
        $this->em->flush();

        $owners = array_column($this->seen, 'owner');
        self::assertContains(Shipment::class, $owners, 'the owner got its postUpdate');

        $atShipment = array_values(array_filter($this->seen, static fn (array $s) => $s['owner'] === Shipment::class))[0];

        self::assertContains(ShipmentLine::class, $atShipment['scheduled'], 'the changed line is still in getScheduledEntityUpdates()');
    }

    public function testAnUntouchedOwnerGetsNoPostUpdateAtAll(): void
    {
        $shipment = $this->shipmentWithTwoLines();
        $this->watchPostUpdate();

        // Only the line changes. Doctrine has nothing to UPDATE on the shipment, so it
        // raises no event for it: an owner's record cannot be built from postUpdate alone.
        $shipment->lines->first()->quantity = 9;
        $this->em->flush();

        self::assertSame([ShipmentLine::class], array_column($this->seen, 'owner'));
    }

    public function testTheChangeSetOfAnElementIsReadableFromTheOwnersEvent(): void
    {
        $shipment = $this->shipmentWithTwoLines();
        $this->watchPostUpdate();

        $shipment->reference = 'SH-3';
        $shipment->lines->first()->quantity = 4;
        $this->em->flush();

        self::assertSame(['quantity' => [1, 4]], $this->lineChangeSet);
    }

    /** @var array<string, mixed> */
    private array $lineChangeSet = [];

    private function shipmentWithTwoLines(): Shipment
    {
        $shipment = new Shipment('SH-1');
        $shipment->add(new ShipmentLine('widget', 1));
        $shipment->add(new ShipmentLine('gadget', 2));

        $this->em->persist($shipment);
        $this->em->flush();
        $this->gateway->documents = [];

        return $shipment;
    }

    private function watchPostUpdate(): void
    {
        $seen = &$this->seen;
        $changeSet = &$this->lineChangeSet;

        $watcher = new class($seen, $changeSet) {
            /**
             * @param list<array{owner: string, scheduled: list<string>}> $seen
             * @param array<string, mixed>                               $changeSet
             */
            public function __construct(private array &$seen, private array &$changeSet)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                $uow = $args->getObjectManager()->getUnitOfWork();
                $scheduled = array_map(static fn (object $e): string => $e::class, array_values($uow->getScheduledEntityUpdates()));

                $this->seen[] = ['owner' => $args->getObject()::class, 'scheduled' => $scheduled];

                if ($args->getObject() instanceof Shipment) {
                    foreach ($uow->getScheduledEntityUpdates() as $entity) {
                        if ($entity instanceof ShipmentLine) {
                            $this->changeSet = $uow->getEntityChangeSet($entity);
                        }
                    }
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postUpdate], $watcher);
    }
}
