<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Doctrine\Common\Collections\ArrayCollection;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Rack;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\RackNote;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\RackSlot;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop;

/**
 * Two shapes the listener has to get right on its way out.
 *
 * A removal is the last thing a history says about an object, and what it says is that
 * the object is gone — not a field-by-field account of a row that no longer exists.
 * Doctrine still has the change set at that moment, so a record built with it would be
 * full of "changes" nobody made.
 *
 * And emptying a collection is the one operation Doctrine reports by saying nothing: it
 * issues a DELETE and leaves no change set behind, so the listener reads the old
 * membership itself. Reading it for a collection nobody audits is work for a record that
 * will never carry it — and, worse, a question asked of the database on behalf of
 * somebody who did not ask for auditing at all.
 */
final class WhatARemovalAndAClearRecordTest extends DoctrineTestCase
{
    public function testARemovalSaysTheObjectIsGoneAndNotWhatItHeld(): void
    {
        $article = new Article('Hello');
        $this->em->persist($article);
        $this->em->flush();

        $this->gateway->documents = [];

        // A field changed in the same flush that removes it, which is the case that
        // tells "no changes" from "we happened not to have any": the change set is
        // there, and the record still must not carry it.
        $article->title = 'Changed on the way out';
        $this->em->remove($article);
        $this->em->flush();

        $document = $this->lastDocument();

        self::assertSame('remove', $document['event']);
        self::assertSame([], $document['changes'], 'a removal is not an account of the row that is gone');
    }

    public function testARemovalSaysNothingAboutACollectionThatMovedOnTheWayOut(): void
    {
        // The test above cannot show this on its own: at preRemove there is no change
        // set yet, so a removal record built *with* the changes turned on comes out
        // empty for an entity whose audited fields are all scalars. An owning collection
        // is the exception — it answers from its own dirty state rather than from the
        // change set — and this one is dirty, because the same operation took a stop off
        // the route before deleting it.
        //
        // What must not happen is the removal turning into an account of that. "stops
        // went from two to one" beside a deletion describes a row nobody can find, and
        // an always-recorded field would arrive with it, since context is only added to
        // a record that already has something in it.
        $route = new Route('R-1');
        $route->stops->add($first = new Stop('a'));
        $route->stops->add(new Stop('b'));

        $this->em->persist($first);
        $this->em->persist($route->stops->get(1));
        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $route->stops->removeElement($first);
        $this->em->remove($route);
        $this->em->flush();

        $removals = array_values(array_filter(
            $this->documents(),
            static fn (array $d): bool => $d['event'] === 'remove',
        ));

        self::assertCount(1, $removals, 'the premise: the route was deleted and said so');
        self::assertSame([], $removals[0]['changes'], 'the removal carried an account of a collection of a row that is gone');
    }

    public function testClearingACollectionNobodyAuditsIsNotRememberedAtAll(): void
    {
        // detours is mapped and not audited. Emptying it is an ordinary thing for an
        // application to do, and the listener must not go and read what used to be in it.
        $route = new Route('R-1');
        $route->stops->add($stop = new Stop('a'));
        $route->detours->add($detour = new Stop('b'));

        $this->em->persist($stop);
        $this->em->persist($detour);
        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $route->detours->clear();
        $route->code = 'R-2';
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'R-1', 'new' => 'R-2'], $changes['code'] ?? null, 'the premise: the audited field is recorded');
        self::assertArrayNotHasKey('detours', $changes, 'a collection nobody audits says nothing when it is emptied');
    }

    public function testTheCollectionNobodyAuditsDoesNotEndTheReadingOfTheOnesNextToIt(): void
    {
        // Two collections emptied in one flush, the unaudited one first. Skipping it is
        // right; stopping at it is not, and the two look identical until there is
        // something behind it to lose. What would be lost is the one operation Doctrine
        // reports by saying nothing — a clear() leaves an empty snapshot and a
        // collection that says it is not dirty — so the record would come out saying the
        // stops never moved while the join rows were deleted.
        $route = new Route('R-1');
        $route->stops->add($stop = new Stop('a'));
        $route->detours->add($detour = new Stop('b'));

        $this->em->persist($stop);
        $this->em->persist($detour);
        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $route->detours->clear();   // scheduled first, and nobody audits it
        $route->stops->clear();
        $route->code = 'R-2';       // so there is an update for the collections to ride on
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(
            ['old' => ['a'], 'new' => []],
            $changes['stops'] ?? null,
            'the audited collection was emptied and the record does not say so',
        );
    }

    public function testAnEmptiedCollectionIsRecordedAsAListEvenWhenDoctrineKeysIt(): void
    {
        // A collection Doctrine keys by one of its elements' columns comes back as a map,
        // and what the record carries has to be a list all the same: a field that is an
        // array in one document and an object in another cannot be mapped, and
        // Elasticsearch finds that out on the day the second one arrives.
        //
        // Emptying it is the path where the old side comes straight off the snapshot the
        // listener kept, which is the keyed array as Doctrine handed it over. Two slots,
        // because one entry is a list whichever way the keys fell.
        $rack = new Rack('R-1');
        $rack->add($a = new RackSlot('a'));
        $rack->add($b = new RackSlot('b'));

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($rack);
        $this->em->flush();

        // Out and back, and read once, so the snapshot is the one Doctrine builds from
        // the database — keyed by code — rather than the array this test just filled.
        $id = $rack->id;
        $this->em->clear();

        $rack = $this->em->find(Rack::class, $id);

        self::assertNotNull($rack);
        self::assertSame(['a', 'b'], array_keys($rack->slots->toArray()), 'the premise: Doctrine keys this collection by code');

        $this->gateway->documents = [];

        $rack->slots->clear();
        $rack->name = 'R-2';
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame([0, 1], array_keys($changes['slots']['old'] ?? []), 'the old side went into the record keyed, so it is an object and not an array');
        self::assertSame(['a', 'b'], $changes['slots']['old'] ?? null);
        self::assertSame([], $changes['slots']['new'] ?? null);
    }

    public function testACollectionReplacedByAKeyedOneIsRecordedAsAListOnBothSides(): void
    {
        // The other side of the same record. Replacing a collection is the second way
        // Doctrine reports an emptying — the old one is scheduled for deletion and the
        // field holds something new — so the "old" side comes off the snapshot and the
        // "new" side off whatever the application put there, which here is keyed too.
        //
        // Both sides of one field, and both have to be arrays. Writing a list on one
        // side and a map on the other is the same mapping problem as writing a map on
        // both, discovered one document later.
        $rack = new Rack('R-1');
        $rack->add($a = new RackSlot('a'));
        $rack->add($b = new RackSlot('b'));

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($rack);
        $this->em->flush();

        $id = $rack->id;
        $this->em->clear();

        $rack = $this->em->find(Rack::class, $id);
        $b = $this->em->find(RackSlot::class, $b->id);

        self::assertNotNull($rack);
        self::assertNotNull($b);
        self::assertSame(['a', 'b'], array_keys($rack->slots->toArray()), 'the premise: Doctrine keys this collection by code');

        $this->gateway->documents = [];

        // Keyed the same way the application would key it, because that is what it just
        // read out of Doctrine.
        $rack->slots = new ArrayCollection(['b' => $b]);
        $rack->name = 'R-2';
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame([0, 1], array_keys($changes['slots']['old'] ?? []), 'the old side went into the record keyed');
        self::assertSame([0], array_keys($changes['slots']['new'] ?? []), 'the new side went into the record keyed');
        self::assertSame(['a', 'b'], $changes['slots']['old'] ?? null);
        self::assertSame(['b'], $changes['slots']['new'] ?? null);
    }

    public function testEmptyingAnAuditedCollectionIsRecordedWithNothingElseToRideOn(): void
    {
        // clear() schedules the join rows for deletion and dirties nothing on the owner,
        // so Doctrine gives the owner no lifecycle event — and every record this listener
        // builds for an entity is built from one. The rows go, the history says nothing,
        // and no warning anywhere.
        //
        // Every other test on this path gives the owner a column to change as well, which
        // is exactly what hid it: with an UPDATE of its own the owner gets its event and
        // the emptied collection rides along on that.
        $route = new Route('R-1');
        $route->stops->add($a = new Stop('a'));
        $route->stops->add($b = new Stop('b'));

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $route->stops->clear();
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM route_stop'), 'the premise: the join rows are gone');

        $document = $this->lastDocument();

        self::assertSame('route', $document['objectType']);
        self::assertSame(['old' => ['a', 'b'], 'new' => []], $document['changes']['stops'] ?? null);
    }

    public function testAnOwnerKeepsBothKindsOfNewsWhenItHasNoEventOfItsOwn(): void
    {
        // Two roads meet in one record. The rack emptied a collection, so what it held
        // comes off the snapshot the listener kept; a line inside it changed, so that
        // comes off the element map. The rack itself was never updated, so Doctrine gave
        // it no event and the record is built after the flush from both — and keeping
        // only one of them is a history that is half true.
        $rack = new Rack('R-1');
        $rack->add($a = new RackSlot('a'));
        $rack->note($note = new RackNote('as written'));

        $this->em->persist($a);
        $this->em->persist($rack);
        $this->em->flush();

        $this->gateway->documents = [];

        $rack->slots->clear();
        $note->text = 'corrected';
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => ['a'], 'new' => []], $changes['slots'] ?? null, 'the emptied collection is missing from a record that has the element news');
        self::assertSame(['old' => 'as written', 'new' => 'corrected'], $changes['notes.'.$note->id.'.text'] ?? null, 'the element news is missing from a record that has the emptied collection');
    }
}
