<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;

/**
 * A collection records which elements it has; what changes inside one of them is a
 * change to the element, which Doctrine reports separately and the owner's history
 * would otherwise never mention.
 */
final class CollectionElementsTest extends DoctrineTestCase
{
    private function useComparator(\Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface $comparator): void
    {
        // attachListener() replaces the listener setUp() attached.
        $this->attachListener(\Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy::Log, $comparator);
    }

    public function testAnElementIdCarryingADotCannotBecomeAnotherElementsField(): void
    {
        // Identifiers may be arbitrary strings, and the flattened key joins them with
        // dots: an element whose id is "42.quantity" writes the membership key
        // "lines.42.quantity" — which is exactly the field-change key of element 42.
        // One would silently overwrite the other in the same flush. The id segment is
        // escaped, so an id with no dot is written exactly as before.
        self::assertSame('lines.42\\.quantity', \Borsche\ElasticsearchAuditBundle\Doctrine\ElementKey::of('lines', '42.quantity'));
        self::assertSame('lines.42', \Borsche\ElasticsearchAuditBundle\Doctrine\ElementKey::of('lines', 42), 'the ordinary id is untouched');
        self::assertSame('lines.42.quantity', \Borsche\ElasticsearchAuditBundle\Doctrine\ElementKey::field('lines', 42, 'quantity'));
        self::assertNotSame(
            \Borsche\ElasticsearchAuditBundle\Doctrine\ElementKey::of('lines', '42.quantity'),
            \Borsche\ElasticsearchAuditBundle\Doctrine\ElementKey::field('lines', 42, 'quantity'),
            'the two must never spell the same'
        );
        self::assertSame('lines.a\\\\b', \Borsche\ElasticsearchAuditBundle\Doctrine\ElementKey::of('lines', 'a\\b'), 'the escape character escapes itself');
    }

    public function testAQuantityChangedInsideALineIsRecordedOnTheShipment(): void
    {
        $shipment = $this->shipment();

        $shipment->lines->first()->quantity = 7;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];
        $lineId = $shipment->lines->first()->id;

        self::assertSame(['old' => 1, 'new' => 7], $changes['lines.'.$lineId.'.quantity']);
        self::assertArrayNotHasKey('lines', $changes, 'the collection itself did not change: nothing was added or removed');
    }

    public function testAnOwnerNobodyTouchedStillGetsItsRecord(): void
    {
        // No column of the shipment changed, so Doctrine raises no postUpdate for it.
        $shipment = $this->shipment();

        $shipment->lines->first()->quantity = 3;
        $this->em->flush();

        $document = $this->lastDocument();

        self::assertSame('shipment', $document['objectType']);
        self::assertSame((string) $shipment->id, (string) $document['objectId']);
        self::assertSame('update', $document['event']);
    }

    public function testTheOwnersOwnChangesAndItsLinesAreOneRecord(): void
    {
        $shipment = $this->shipment();

        $shipment->reference = 'SH-2';
        $shipment->lines->first()->quantity = 5;
        $this->em->flush();

        self::assertCount(1, $this->documents(), 'one update, one record');

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'SH-1', 'new' => 'SH-2'], $changes['reference']);
        self::assertSame(['old' => 1, 'new' => 5], $changes['lines.'.$shipment->lines->first()->id.'.quantity']);
    }

    public function testOnlyTheDeclaredFieldsOfAnElementAreTaken(): void
    {
        $shipment = $this->shipment();

        // "product" is not in trackElements: ['quantity'].
        $shipment->lines->first()->product = 'widget-mk2';
        $shipment->lines->first()->quantity = 2;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];
        $lineId = $shipment->lines->first()->id;

        self::assertArrayHasKey('lines.'.$lineId.'.quantity', $changes);
        self::assertArrayNotHasKey('lines.'.$lineId.'.product', $changes);
    }

    public function testALineAddedToAnInverseCollectionIsRecordedToo(): void
    {
        // The inverse side never goes dirty — Doctrine watches the line's own reference
        // back — so without tracking this flush would leave no trace at all.
        $shipment = $this->shipment();

        $bolt = new ShipmentLine('bolt', 4);
        $shipment->add($bolt);
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => null, 'new' => 'bolt'], $changes['lines.'.$bolt->id]);
    }

    public function testALineTakenAwayIsRecordedAsGone(): void
    {
        $shipment = $this->shipment();
        $gadget = $shipment->lines->last();
        $gadgetId = $gadget->id; // Doctrine clears it once the row is gone

        $this->em->remove($gadget);
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'gadget', 'new' => null], $changes['lines.'.$gadgetId]);
    }

    public function testAFlushThatChangedNoLineWritesNothing(): void
    {
        $this->shipment();

        $this->em->flush();

        self::assertSame([], $this->documents(), 'an untouched collection costs nothing and says nothing');
    }

    public function testAnUntrackedCollectionIgnoresItsElements(): void
    {
        $shipment = $this->shipment();
        $line = $shipment->lines->first();

        // Same shape, but the audited collection of Article is not tracked.
        $line->quantity = 8;
        $this->em->flush();
        $this->gateway->documents = [];

        $line->quantity = 9;
        $this->em->getUnitOfWork()->clear(ShipmentLine::class);

        self::assertSame([], $this->documents());
    }

    public function testRemovingTheOwnerWithItsElementsIsOneRemoveAndNothingAfterIt(): void
    {
        // An assigned identifier survives the DELETE, so nothing but the listener's own
        // judgement keeps a removed owner from getting an "update: items gone" after its remove.
        $crate = new Crate('CR-1');
        $crate->add(new CrateItem('bolt'));
        $this->em->persist($crate);
        $this->em->flush();
        $this->gateway->documents = [];

        $this->em->remove($crate);
        $this->em->flush();

        self::assertSame(['remove'], array_column($this->documents(), 'event'), 'the crate is gone; its lines going with it is not a second event');
    }

    public function testARuleAboutAFieldAppliesToThatFieldInsideAnElementToo(): void
    {
        // numeric_fields: [quantity] — the rule is about quantities wherever they are, the
        // way a redaction rule for "password" covers "items.42.password".
        $this->useComparator(new NumericNullAsZeroComparator(['quantity']));

        $crate = new Crate('CR-2');
        $crate->add($item = new CrateItem('bolt', null));
        $this->em->persist($crate);
        $this->em->flush();
        $this->gateway->documents = [];

        $item->quantity = 0;   // null → 0: Doctrine reports a change, the rule says it is none
        $this->em->flush();

        self::assertSame([], $this->documents(), 'null → 0 on a quantity is not a change inside an element either');

        $item->quantity = 5;
        $this->em->flush();

        self::assertSame(['old' => 0, 'new' => 5], $this->lastDocument()['changes']['items.'.$item->id.'.quantity'], 'a real change still is one');
    }

    private function shipment(): Shipment
    {
        $shipment = new Shipment('SH-1');
        $shipment->add(new ShipmentLine('widget', 1));
        $shipment->add(new ShipmentLine('gadget', 2));

        $this->em->persist($shipment);
        $this->em->flush();
        $this->gateway->documents = [];

        return $shipment;
    }
}
