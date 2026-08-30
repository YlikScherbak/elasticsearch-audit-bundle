<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;

/**
 * Which owner a tracked element belongs to is itself history. A line that moves from
 * one shipment to another leaves one and joins the other, and both have to say so —
 * the collection it left is not dirty, and the element's own fields may not have
 * changed at all, so without reading the association's change set nobody records it.
 */
final class ElementOwnershipTest extends DoctrineTestCase
{
    public function testALineThatMovedIsRecordedOnBothShipments(): void
    {
        [$a, $b] = $this->twoShipments();
        $line = $a->lines->first();
        $lineId = $line->id;

        $this->move($line, $a, $b);
        $this->em->flush();

        $changes = $this->changesByObjectId();

        self::assertSame(['old' => 'widget', 'new' => null], $changes[(string) $a->id]['lines.'.$lineId], 'the shipment it left');
        self::assertSame(['old' => null, 'new' => 'widget'], $changes[(string) $b->id]['lines.'.$lineId], 'the shipment it joined');
    }

    public function testAMoveThatAlsoChangedTheLineDoesNotBackdateTheChange(): void
    {
        // The new owner never held the old quantity, so recording "1 → 5" against it
        // would describe a state that shipment was never in.
        [$a, $b] = $this->twoShipments();
        $line = $a->lines->first();
        $lineId = $line->id;

        $line->quantity = 5;
        $this->move($line, $a, $b);
        $this->em->flush();

        $changes = $this->changesByObjectId();

        self::assertSame(['lines.'.$lineId], array_keys($changes[(string) $b->id]));
        self::assertArrayNotHasKey('lines.'.$lineId.'.quantity', $changes[(string) $b->id]);
        self::assertArrayNotHasKey('lines.'.$lineId.'.quantity', $changes[(string) $a->id]);
    }

    public function testALineDetachedFromItsShipmentIsRecordedAsGone(): void
    {
        [$a] = $this->twoShipments();
        $line = $a->lines->first();
        $lineId = $line->id;

        $line->shipment = null;
        $a->lines->removeElement($line);
        $this->em->flush();

        self::assertSame(['old' => 'widget', 'new' => null], $this->changesByObjectId()[(string) $a->id]['lines.'.$lineId]);
    }

    public function testALineAttachedToAShipmentIsRecordedAsArrived(): void
    {
        [$a, $b] = $this->twoShipments();
        $line = $a->lines->first();
        $lineId = $line->id;

        $line->shipment = null;
        $a->lines->removeElement($line);
        $this->em->flush();
        $this->gateway->documents = [];

        $line->shipment = $b;
        $b->lines->add($line);
        $this->em->flush();

        self::assertSame(['old' => null, 'new' => 'widget'], $this->changesByObjectId()[(string) $b->id]['lines.'.$lineId]);
    }

    public function testANewShipmentWithItsLinesIsOneCreate(): void
    {
        $shipment = new Shipment('SH-NEW');
        $shipment->add(new ShipmentLine('widget', 1));
        $shipment->add(new ShipmentLine('gadget', 2));

        $this->em->persist($shipment);
        $this->em->flush();

        $documents = $this->documents();

        self::assertCount(1, $documents, 'nobody updated the shipment; it was created');
        self::assertSame('create', $documents[0]['event']);
    }

    public function testANewShipmentKeepsItsOwnChangesBesideItsLines(): void
    {
        $shipment = new Shipment('SH-NEW');
        $line = new ShipmentLine('widget', 1);
        $shipment->add($line);

        $this->em->persist($shipment);
        $this->em->flush();

        $changes = $this->documents()[0]['changes'];

        self::assertSame(['old' => null, 'new' => 'SH-NEW'], $changes['reference'], 'the create still describes the shipment itself');
        self::assertSame(['old' => null, 'new' => 'widget'], $changes['lines.'.$line->id], 'and the lines it arrived with');
    }

    /**
     * @return array{0: Shipment, 1: Shipment}
     */
    private function twoShipments(): array
    {
        $a = new Shipment('SH-A');
        $a->add(new ShipmentLine('widget', 1));
        $b = new Shipment('SH-B');

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();
        $this->gateway->documents = [];

        return [$a, $b];
    }

    private function move(ShipmentLine $line, Shipment $from, Shipment $to): void
    {
        $line->shipment = $to;
        $from->lines->removeElement($line);
        $to->lines->add($line);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function changesByObjectId(): array
    {
        $byId = [];

        foreach ($this->documents() as $document) {
            $byId[(string) $document['objectId']] = $document['changes'];
        }

        return $byId;
    }
}
