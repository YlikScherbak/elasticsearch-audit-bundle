<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Folder;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\FolderDocument;
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

    public function testEachOwnersLinesJoinItsOwnRecordAndNoOtherIsLeftOut(): void
    {
        // Two crates, each changed and each with a line changed, in one flush: every line's
        // change is in its own crate's record. Joining the first crate's lines is not the end
        // of the reading -- with it treated as one, the second crate's line went unrecorded.
        $this->em->persist($a = new Crate('A'));
        $a->add($first = new CrateItem('SKU-A'));
        $this->em->persist($b = new Crate('B'));
        $b->add($second = new CrateItem('SKU-B'));
        $this->em->flush();
        $this->gateway->documents = [];

        $a->status = 'shipped';
        $first->quantity = 5;
        $b->status = 'lost';
        $second->quantity = 6;
        $this->em->flush();

        self::assertSame(
            [['A', ['old' => 1, 'new' => 5]], ['B', ['old' => 1, 'new' => 6]]],
            array_map(static fn (array $d): array => [$d['objectId'], $d['changes']['items.'.($d['objectId'] === 'A' ? $first->id : $second->id).'.quantity'] ?? null], $this->documents()),
        );
    }

    public function testAnOwnersRecordStandsWhereItsOwnStatementRanNotWhereItsLinesDid(): void
    {
        // Two crates changed, and a line of the first, in one flush. Doctrine runs the crates'
        // UPDATEs in the order it holds them, the first's first, and the line's after both --
        // the line's class refers to the crate's. The line joins the first crate's record, and
        // that record still begins where the crate's own UPDATE ran: before the second's.
        $this->em->persist($first = new Crate('A'));
        $first->add($line = new CrateItem('SKU'));
        $this->em->persist($second = new Crate('B'));
        $this->em->flush();
        $this->gateway->documents = [];
        $this->queries = [];

        $first->status = 'shipped';
        $second->status = 'lost';
        $line->quantity = 4;
        $this->em->flush();

        $updates = array_values(array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')));
        self::assertSame(['Crate', 'Crate', 'CrateItem'], array_map(static fn (string $sql): string => explode(' ', $sql)[1], $updates), 'the premise: the other crate\'s statement between the owner\'s and its line\'s');

        self::assertSame(['A', 'B'], array_column($this->documents(), 'objectId'));
        self::assertArrayHasKey('items.'.$line->id.'.quantity', $this->documents()[0]['changes'], 'the line is in the first crate\'s record');
    }

    /** @return iterable<string, array{string}> */
    public static function emptiedBeforeTheCrate(): iterable
    {
        yield 'a collection nobody audits' => ['detours'];
        yield 'a collection Doctrine does not empty' => ['lines'];
    }

    /**
     * A collection with nothing to say about it, emptied in the same flush before the one that
     * has: passing over the first is not the end of the reading. Without the second's rows, read
     * before its DELETE, what the crate lost could not be followed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptiedBeforeTheCrate')]
    public function testAnEmptyingPassedOverDoesNotTakeTheNextWithIt(string $first): void
    {
        $this->em->persist($route = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route('R-1'));
        $route->detours->add($stop = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop('a'));
        $this->em->persist($stop);
        $this->em->persist($shipment = new Shipment('SH-1'));
        $shipment->lines->add($line = new ShipmentLine('p', 1));
        $line->shipment = $shipment;
        $this->em->persist($line);
        $this->em->flush();
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->exec("INSERT INTO Crate (code, status, internalNote) VALUES ('C-far', 'packed', '')");
        $native->exec("INSERT INTO CrateItem (id, sku, quantity, crate_id) VALUES (900501, 'FAR', 1, 'C-far')");
        $this->em->clear();
        $this->gateway->documents = [];

        // Loaded before the crate, so that Doctrine schedules it first.
        $before = $first === 'detours' ? $this->em->find(\Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route::class, $route->id) : $this->em->find(Shipment::class, $shipment->id);
        $crate = $this->em->find(Crate::class, 'C-far');
        self::assertNotNull($before);
        self::assertNotNull($crate);

        if ($before instanceof \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route) {
            $before->detours->clear();
        } else {
            $before->lines = new \Doctrine\Common\Collections\ArrayCollection();
        }

        $crate->items = new \Doctrine\Common\Collections\ArrayCollection();
        $this->em->flush();

        self::assertSame([['C-far', ['old' => ['FAR'], 'new' => []]]], array_map(static fn (array $d): array => [$d['objectId'], $d['changes']['items'] ?? null], $this->documents()));
    }

    public function testAnEmptyingOfAnOwnerNobodyAuditsIsPassedOverInSilence(): void
    {
        // Nothing to say, and nothing to fail at: a collection of an entity nobody audits is
        // passed over before anything is asked of its declaration -- under "throw", a question
        // asked of a declaration that is not there would refuse the application's flush.
        $member = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\TrackedGroupMember();
        $member->groups->add($group = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\MisdeclaredInverseTracking());
        $this->em->persist($member);
        $this->em->persist($group);
        $this->em->flush(); // the group's own declaration is refused under the setUp listener's "log"
        $this->logs = [];

        $this->attachListener(\Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy::Throw);
        $member->groups->clear();
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->em->getClassMetadata(\Borsche\ElasticsearchAuditBundle\Tests\Fixtures\TrackedGroupMember::class)->getAssociationMapping('groups')['joinTable']['name']), 'the premise: the links went');
        self::assertSame([], $this->logs);
    }

    public function testAnElementWhoseRepresenterFailedDoesNotTakeTheRestOfTheFlushWithIt(): void
    {
        // A document added to a vault, whose representer throws, and a crate's line changed in
        // the same flush: the document's fact is left out, and the line's is still read.
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($line = new CrateItem('SKU'));
        $this->em->persist($vault = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vault('Contracts'));
        $this->em->flush();
        $this->gateway->documents = [];

        $vault->add(new FolderDocument('lease.pdf'));
        $line->quantity = 4;
        $this->em->flush();
        $this->logs = [];

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertSame([['old' => 1, 'new' => 4]], array_map(static fn (array $d): mixed => $d['changes']['items.'.$line->id.'.quantity'] ?? null, $crates));
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

    public function testAnUntrackedCollectionIgnoresWhatHappensInsideItsElements(): void
    {
        // Folder audits its documents without trackElements: which documents it holds is
        // history, what changes inside one is not. This used to assert nothing at all —
        // it changed a quantity, never flushed, and cleared the unit of work, so an empty
        // history was guaranteed however the listener behaved. It also talked about
        // Article while using Shipment, whose collection *is* tracked.
        $folder = new Folder('Contracts');
        $document = new FolderDocument('lease.pdf');
        $folder->add($document);

        $this->em->persist($folder);
        $this->em->flush();
        $this->gateway->documents = [];

        $document->title = 'lease-signed.pdf';
        $this->em->flush();

        self::assertSame([], $this->documents(), 'a title change inside a document is not the folder\'s history');
    }

    public function testAnUntrackedCollectionStillRecordsWhatJoinsAndLeavesIt(): void
    {
        // The other half, and the reason the test above cannot simply assert silence:
        // membership follows from the field being audited, and only what happens *inside*
        // an element needs trackElements.
        $folder = new Folder('Contracts');
        $this->em->persist($folder);
        $this->em->flush();
        $this->gateway->documents = [];

        $added = new FolderDocument('lease.pdf');
        $folder->add($added);
        $this->em->flush();

        $documents = $this->documents();

        self::assertCount(1, $documents);
        self::assertSame(['old' => null, 'new' => 'lease.pdf'], $documents[0]['changes']['documents.'.$added->id]);
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
