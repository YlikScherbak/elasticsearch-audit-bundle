<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
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
}
