<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Address;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\AlwaysRecordsAnAssociation;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Basket;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\BasketItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Customer;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MisdeclaredTracking;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MisspelledTracking;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\UntrackedInverseManyToMany;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\SometimesMisspelledTracking;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\SometimesRepresented;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;
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

    public function testAMoveAndARemoveInOneFlushBlameTheRightOwner(): void
    {
        // The database row said shipment A; the in-memory back-ref said B. Reading the
        // owner from memory wrote "removed from B" — a shipment that never held it —
        // and said nothing to A, whose line actually went.
        [$a, $b] = $this->twoShipments();
        $line = $a->lines->first();
        $lineId = $line->id;

        $line->shipment = $b;
        $a->lines->removeElement($line);
        $b->lines->add($line);
        $this->em->remove($line);
        $this->em->flush();

        $changes = $this->changesByObjectId();

        self::assertSame(['old' => 'widget', 'new' => null], $changes[(string) $a->id]['lines.'.$lineId], 'the owner that had it in the database');
        self::assertArrayNotHasKey((string) $b->id, $changes, 'no phantom on the owner that never did');
    }

    public function testAnOrphanedItemIsStillRecorded(): void
    {
        // Maker-style removeItem() nulls the back-ref, orphanRemoval schedules the
        // delete: the current owner is null, and the removal used to be recorded
        // nowhere at all.
        $crate = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate('CR-9');
        $item = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem('bolt');
        $crate->add($item);
        $this->em->persist($crate);
        $this->em->flush();
        $itemId = $item->id;
        $this->gateway->documents = [];

        $item->crate = null;
        $crate->items->removeElement($item);
        $this->em->flush();

        self::assertSame(['old' => 'bolt', 'new' => null], $this->changesByObjectId()['CR-9']['items.'.$itemId]);
    }

    public function testTheInverseSideOfAManyToManyIsRefusedToo(): void
    {
        // It has a mappedBy, so the first four checks pass — and the listener still
        // cannot serve it: its elements reach back through a collection.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        // The refusal comes from the wider rule now — an audited collection nothing can
        // report to this side — which covers this shape with trackElements or without:
        // one cause, one message.
        $this->expectExceptionMessage('reach back through a collection of their own');

        $this->em->persist(new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MisdeclaredInverseTracking());
        $this->em->flush();
    }

    public function testARecordMadeOnlyOfElementChangesStillCarriesItsContext(): void
    {
        // alwaysRecord promises that every history line reads on its own. A record
        // assembled after the flush from what the elements did used to skip it.
        $crate = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate('CR-CTX');
        $item = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem('bolt');
        $crate->add($item);
        $this->em->persist($crate);
        $this->em->flush();
        $this->gateway->documents = [];

        $item->quantity = 7;   // the crate itself is untouched
        $this->em->flush();

        $changes = $this->changesByObjectId()['CR-CTX'];

        self::assertSame(['old' => 1, 'new' => 7], $changes['items.'.$item->id.'.quantity']);
        self::assertSame(['old' => 'packed', 'new' => 'packed'], $changes['status'], 'the context that lets the line read on its own');
    }

    public function testAnAmendedRecordWithNoChangesOfItsOwnCarriesItToo(): void
    {
        // The owner got its postUpdate — a non-audited column moved — but none of its
        // audited fields did: the record enters postFlush empty and is amended there.
        $crate = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate('CR-AMD');
        $item = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem('bolt');
        $crate->add($item);
        $this->em->persist($crate);
        $this->em->flush();
        $this->gateway->documents = [];

        $crate->internalNote = 'moved to bay 4';
        $item->quantity = 9;
        $this->em->flush();

        $changes = $this->changesByObjectId()['CR-AMD'];

        self::assertArrayHasKey('items.'.$item->id.'.quantity', $changes);
        self::assertSame(['old' => 'packed', 'new' => 'packed'], $changes['status']);
    }

    public function testACollectionTheListenerCannotWatchIsADeclarationMistake(): void
    {
        // A ManyToMany used to accept trackElements and then record nothing at all.
        // Silence is the worst answer here, so it travels the way other declaration
        // mistakes do: through the failure policy.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('tracks its elements, but it is the owning side');

        $this->em->persist(new MisdeclaredTracking());
        $this->em->flush();
    }

    public function testATypoInTrackElementsIsRefusedRatherThanRecordingNothingForever(): void
    {
        // The declaration names the element fields to watch, and elementChanges() walks
        // Doctrine's change set and skips anything not on that list — so "quanitity"
        // watched nothing at all, for the life of the application, without a word. This
        // is the exact class of mistake assertTrackedCollectionsAreServable() exists to
        // refuse; it checked the collection and not the field names.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('quanitity');

        $this->em->persist(new MisspelledTracking());
        $this->em->flush();
    }

    public function testAnOwnerThatOnlyChangedThroughItsElementsStillHasItsDeclarationChecked(): void
    {
        // The case the checks exist for. An owner whose own columns did not change gets
        // no postUpdate from Doctrine, so its record is assembled in postFlush — after
        // the commit, where "throw" can only tell the caller. The declaration is
        // therefore checked in onFlush, the moment membership first finds an audited
        // owner, and the flush that relied on it is refused before it commits.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));

        $basket = new Basket('weekly');
        $this->em->persist($basket);
        $this->em->flush();

        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('representer');

        $basket->add(new BasketItem('apples'));
        $this->em->flush();
    }

    public function testASecondInstanceDeclaringSomethingElseIsCheckedToo(): void
    {
        // The interface form exists so a declaration can depend on the instance — its
        // own docblock says so — and the validation cache was keyed on the class plus
        // the field *names*. These two instances name the same two fields; one of them
        // audits an association with no representer, which records "something changed"
        // and not what. Checked once, the second one walked straight past the rule.
        $this->attachListener(FailurePolicy::Throw);

        $author = new Author('alice');
        $this->em->persist($author);

        $fine = new SometimesRepresented('with a representer');
        $fine->author = $author;
        $this->em->persist($fine);
        $this->em->flush();

        $broken = new SometimesRepresented('without one', represented: false);
        $broken->author = $author;
        $this->em->persist($broken);

        try {
            $this->em->flush();
            self::fail('the second declaration was not checked');
        } catch (WriteFailedException $refused) {
            self::assertStringContainsString('is an audited association and has no representer', self::chain($refused), 'refused by the declaration check, before anything was built');
        }
    }

    public function testASecondInstanceTrackingOtherElementFieldsIsCheckedToo(): void
    {
        // The tracking half of the same cache. The collection names were the key, and
        // what the check reads is the element fields named inside them: one of these two
        // instances watches "quanitity", which watches nothing at all — the silence the
        // check exists to refuse.
        $this->attachListener(FailurePolicy::Throw);

        $spelled = new SometimesMisspelledTracking('SM-1');
        $this->em->persist($spelled);
        $this->em->flush();

        $misspelled = new SometimesMisspelledTracking('SM-2', spelled: false);
        $this->em->persist($misspelled);

        try {
            $this->em->flush();
            self::fail('the second declaration was not checked');
        } catch (WriteFailedException $refused) {
            self::assertStringContainsString('quanitity', self::chain($refused));
        }
    }

    private static function chain(\Throwable $e): string
    {
        $said = [];

        for (; $e !== null; $e = $e->getPrevious()) {
            $said[] = $e->getMessage();
        }

        return implode(' | ', $said);
    }

    public function testAnUntrackedInverseManyToManyIsRefusedRatherThanSilentlyIgnored(): void
    {
        // trackElements: true on this shape is already refused, and refusing only that
        // left the quieter half open: without it the declaration reads as supported and
        // records nothing either. ChangeSetBuilder skips inverse collections on purpose
        // — Doctrine persists the owning side — and membership travels through an
        // element's single-valued reference back, which a ManyToMany element does not
        // have. Two roads, both closed, and no word about it.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('owning side');

        $this->em->persist(new UntrackedInverseManyToMany());
        $this->em->flush();
    }

    public function testAnAssociationCannotBeAlwaysRecorded(): void
    {
        // alwaysRecord names the context every history line carries — a status, an owner
        // name — and it is read off the entity and stored as it is. withAlwaysRecorded()
        // skips associations, so naming one was accepted at boot and then honoured
        // nowhere: the promise of "every line reads on its own" quietly did not hold for
        // that field.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('always recorded');

        $this->em->persist(new AlwaysRecordsAnAssociation());
        $this->em->flush();
    }

    public function testAnAuditedCollectionWithoutARepresenterIsRefusedRatherThanRecordedAsNothing(): void
    {
        // An audited association needs a callable saying what to store for a related
        // object; ChangeSetBuilder enforces that. The membership path did not: it kept
        // the (absent) representer and answered with null, so a folder that gained a
        // document produced "documents.42: null → null" — a history line that exists,
        // looks valid and means nothing. For an audit trail that is worse than an error.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('representer');

        $basket = new Basket('weekly');
        $basket->add(new BasketItem('apples'));

        $this->em->persist($basket);
        $this->em->flush();
    }

    public function testAuditingAnEmbeddedPropertyIsRefusedRatherThanIgnored(): void
    {
        // Doctrine stores an embeddable as columns of its owner and reports them in the
        // change set as "address.city" — never as "address". So #[AuditField] on the
        // embedded property matched nothing, every time, and said nothing about it: the
        // field simply never appeared in any record. Name the fields instead, or drop
        // the declaration; either way the bundle has to say which.
        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        )));
        $this->attachListener(FailurePolicy::Throw);

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('address.city');

        $this->em->persist(new Customer('Ada', new Address('Kyiv', 'Khreshchatyk 1')));
        $this->em->flush();
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
