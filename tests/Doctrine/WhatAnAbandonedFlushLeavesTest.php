<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Doctrine\ORM\EntityManagerInterface;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Depot;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PackingCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop;
use Doctrine\ORM\Events;

/**
 * A flush that never came back, and what the next one does about it.
 *
 * postFlush is where this listener publishes, and it is not guaranteed to run: a
 * listener registered before it can throw and take the rest of the event with it, and a
 * flush aborted in onFlush never reaches it at all. Those two look identical from here —
 * a stack with a level left on it — and they need opposite treatment. One committed and
 * its records are history that has not been written yet; the other wrote nothing and its
 * records describe rows nobody has.
 *
 * Both paths end in a warning, because a trail arriving late and a trail being dropped
 * are things an operator has to know about, and both warnings say how many records they
 * are about — which is the difference between a note and an incident.
 *
 * TransactionSafetyTest covers what the application sees. This one covers what the
 * listener says and what it forgets, which is what mutation testing found nothing
 * standing behind.
 */
final class WhatAnAbandonedFlushLeavesTest extends DoctrineTestCase
{
    public function testAFlushThatCommittedWithoutReachingTheListenerIsWrittenLate(): void
    {
        // The rows are in the database. Dropping their records would be the audit trail
        // losing what actually happened, so they go out with the next flush and a
        // warning that names the priority problem behind it.
        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('Committed'));
        $this->em->flush();

        self::assertSame([], $this->documents(), 'the premise: publishing never ran for that flush');

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertSame(['Committed', 'Next'], array_map(
            static fn (array $d): mixed => $d['changes']['title']['new'],
            $this->documents(),
        ), 'the committed record is written late, before the one that carried it out');

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '1 audit record(s) are being written now, late'),
        ), sprintf("no warning said one record arrived late; what was logged:\n%s", implode("\n", $this->logs)));
    }

    public function testTheWarningCountsEveryRecordItIsAbout(): void
    {
        // Two, and the warning has to say two: an operator reading "1" for a flush that
        // left fifty records unwritten has been told the wrong size of problem.
        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('One'));
        $this->em->persist(new Article('Two'));
        $this->em->flush();

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '2 audit record(s) are being written now, late'),
        ), sprintf("the warning did not count both records; what was logged:\n%s", implode("\n", $this->logs)));
    }

    public function testAFlushWhoseOnlyNewsCameFromInsideACollectionIsWrittenLateToo(): void
    {
        // The records of a flush are not all in one list. An owner whose own columns did
        // not change gets no event from Doctrine at all, so what happened inside its
        // collection waits in a map of its own until postFlush builds the record from
        // it — and postFlush is the event that did not happen here.
        //
        // Asked only about the list of finished records, such a flush looks like one that
        // collected nothing and is dropped: the case is fifteen kilos heavier in the
        // database and the history does not mention it, under a warning saying zero
        // records were lost.
        $depot = new Depot('north');
        $depot->add($case = new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();

        // Only the element moves, so the depot itself is never updated and nothing
        // reaches the list of finished records.
        $case->weight = 25;
        $this->em->flush();

        self::assertSame([], $this->documents(), 'the premise: publishing never ran for that flush');

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        $changes = array_column($this->documents(), 'changes');

        self::assertNotSame([], array_filter(
            $changes,
            static fn (array $c): bool => isset($c['cases.'.$case->id.'.weight']),
        ), sprintf("the element's history was dropped with the flush that made it; what was logged:
%s", implode("
", $this->logs)));

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '1 audit record(s) are being written now, late'),
        ), sprintf("the warning did not count the owner whose news came from its collection; what was logged:
%s", implode("
", $this->logs)));
    }

    public function testAFlushAVetoAbortedHasItsCollectionNewsDroppedRatherThanWrittenTwice(): void
    {
        // The mirror, and the reason the list of finished records could not simply be
        // widened. A listener that throws in onFlush — a validation veto, the usual
        // reason — aborts the flush before Doctrine writes anything and before
        // UnitOfWork::commit() enters the try whose catch closes the manager. So it
        // leaves exactly what a committed flush with a broken postFlush leaves: state on
        // this listener and an open manager.
        //
        // What it does not leave is a row. Publishing its collection news would be a
        // record for something that never happened — and, because Doctrine keeps the
        // entity dirty, the flush that really writes it records it again, so the history
        // would carry one change twice.
        $depot = new Depot('north');
        $depot->add($case = new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        // Registered after this listener, so onFlush collected the element news first
        // and this one then took the flush down.
        $veto = new class {
            public bool $angry = true;

            public function onFlush(): void
            {
                if ($this->angry) {
                    throw new \DomainException('this entity may not be saved');
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        $case->weight = 25;

        try {
            $this->em->flush();
            self::fail('the veto should have taken the flush down');
        } catch (\DomainException) {
        }

        $veto->angry = false;

        // The entity is still dirty, so this is the flush that actually writes the row —
        // and the only one entitled to a record of it.
        $this->em->persist(new Article('Next'));
        $this->em->flush();

        $written = array_values(array_filter(
            $this->documents(),
            static fn (array $d): bool => isset($d['changes']['cases.'.$case->id.'.weight']),
        ));

        self::assertCount(1, $written, 'the change the veto stopped was recorded by the flush that stopped it as well as by the one that wrote it');
        self::assertSame(['old' => 10, 'new' => 25], $written[0]['changes']['cases.'.$case->id.'.weight']);
    }

    public function testAnOwnerWhoseCollectionBothGainedAndChangedIsCountedOnce(): void
    {
        // The count is of records, not of the maps it reads. An owner that gained a line
        // *and* had a field move inside another one is in both of them, and it gets one
        // record — so a warning saying two would have an operator looking for a second
        // one that was never lost.
        $depot = new Depot('north');
        $depot->add($existing = new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();

        $existing->weight = 25;                       // news in one map
        $depot->add(new PackingCase('shelf-b', 5));   // and in the other
        $this->em->flush();

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '1 audit record(s) are being written now, late'),
        ), sprintf("the owner was counted once per map it appears in; what was logged:
%s", implode("
", $this->logs)));
    }

    public function testAnOwnerWhoseCollectionOnlyGainedALineIsCountedToo(): void
    {
        // The other map on its own. A line added and nothing inside any line changed:
        // read from the map of changes alone, this flush has collected nothing.
        $depot = new Depot('north');
        $depot->add(new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();

        $depot->add($added = new PackingCase('shelf-b', 5));
        $this->em->persist($added);
        $this->em->flush();

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        $changes = array_column($this->documents(), 'changes');

        self::assertNotSame([], array_filter(
            $changes,
            static fn (array $c): bool => isset($c['cases.'.$added->id]),
        ), sprintf("the line the collection gained was dropped with the flush that added it; what was logged:
%s", implode("
", $this->logs)));
    }

    public function testAFlushThatCollectedNothingIsNotSaidToBeWritingItLate(): void
    {
        // A flush that touched only what nobody audits, and whose postFlush was swallowed
        // like the others'. The warning is still worth having — a postFlush listener that
        // throws is the operator's problem whether or not it cost anything this time —
        // but it must be the one about a flush that is being let go, not the one about
        // records on their way out. Nothing is on its way out.
        $depot = new Depot('north');

        $this->em->persist($depot);
        $this->em->flush();

        $this->logs = [];
        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();

        $depot->note = 'nobody audits this';
        $this->em->flush();

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, 'are being written now, late'),
        ), sprintf("a flush that collected nothing was announced as writing records late; what was logged:
%s", implode("
", $this->logs)));

        self::assertSame(['Next'], array_map(
            static fn (array $d): mixed => $d['changes']['title']['new'] ?? null,
            $this->documents(),
        ), 'and nothing of its own reached the history');
    }

    public function testAFlushWhoseOnlyNewsWasAnEmptiedCollectionIsWrittenLateToo(): void
    {
        // The one kind of flush with no statements of its own to be asked about. clear()
        // dirties nothing on the owner, so no entity event fires and the flag that tells
        // a committed flush from a vetoed one never goes up — however well this flush
        // went. The join rows are gone and the history has to say so.
        $route = new Route('R-1');
        $route->stops->add($a = new Stop('a'));

        $this->em->persist($a);
        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();

        $route->stops->clear();
        $this->em->flush();

        self::assertSame([], $this->documents(), 'the premise: publishing never ran for that flush');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM route_stop'), 'and the rows went all the same');

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        $changes = array_column($this->documents(), 'changes');

        self::assertNotSame([], array_filter(
            $changes,
            static fn (array $c): bool => isset($c['stops']),
        ), sprintf("the emptying was dropped with the flush that did it; what was logged:
%s", implode("
", $this->logs)));
    }

    public function testAnEmptyingAVetoStoppedIsDroppedAndThenRecordedOnceByTheFlushThatDoesIt(): void
    {
        // The mirror, and the reason the question is asked of the collection rather than
        // assumed. A veto in onFlush leaves this listener the same state a committed
        // flush with a broken postFlush leaves — but the unit of work still has the
        // deletion on its list, because it clears that list only after a commit.
        //
        // So the vetoed flush's news is dropped, and the flush that actually deletes the
        // rows records the emptying itself. Once, not twice.
        $route = new Route('R-1');
        $route->stops->add($a = new Stop('a'));

        $this->em->persist($a);
        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $veto = new class {
            public bool $angry = true;

            public function onFlush(): void
            {
                if ($this->angry) {
                    throw new \DomainException('this may not be saved');
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        $route->stops->clear();

        try {
            $this->em->flush();
            self::fail('the veto should have taken the flush down');
        } catch (\DomainException) {
        }

        $veto->angry = false;

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM route_stop'), 'the premise: this flush is the one that deleted them');

        $emptyings = array_values(array_filter(
            array_column($this->documents(), 'changes'),
            static fn (array $c): bool => isset($c['stops']),
        ));

        self::assertCount(1, $emptyings, 'the emptying was recorded by the flush the veto stopped as well as by the one that carried it out');
        self::assertSame(['old' => ['a'], 'new' => []], $emptyings[0]['stops']);
    }

    public function testSomebodyElsesCollectionBeingEmptiedDoesNotLookLikeARefusalOfOurs(): void
    {
        // The question asked of the unit of work is whether *this owner's* collection is
        // still waiting to be deleted — not whether anything at all is. Flushes that
        // empty collections are not rare in an application that has them, and reading
        // any scheduled deletion as "the previous flush never committed" would drop a
        // history for the sole reason that an unrelated route was being emptied at the
        // same moment.
        $first = new Route('R-1');
        $first->stops->add($a = new Stop('a'));
        $second = new Route('R-2');
        $second->stops->add($b = new Stop('b'));

        foreach ([$a, $b] as $stop) {
            $this->em->persist($stop);
        }

        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();

        $first->stops->clear();
        $this->em->flush();

        $this->restorePostFlush($listener);

        // The next flush empties a different route's collection, so a deletion is on the
        // unit of work's list while the question is being asked — and it is not ours.
        $second->stops->clear();
        $this->em->flush();

        $emptyings = array_values(array_filter(
            array_column($this->documents(), 'changes'),
            static fn (array $c): bool => isset($c['stops']),
        ));

        self::assertCount(2, $emptyings, sprintf("a stranger's emptying was read as this one's refusal; what was logged:
%s", implode("
", $this->logs)));
    }

    public function testAFlushThatOnlyRemovedThingsIsWrittenLateToo(): void
    {
        // A flush whose whole contribution to the history is deletions. Its records are
        // taken in preRemove — before there is a change set to take — and moved across in
        // postRemove once the row is really gone, so by the time the next flush looks
        // they are in the same list as everything else. What is being asked here is that
        // such a flush counts as one that collected something at all: read as empty, the
        // rows are gone from the database and the history still shows them, which is the
        // one shape of wrong an audit trail cannot be read around.
        $this->em->persist($article = new Article('To be deleted'));
        $this->em->flush();

        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();

        $this->em->remove($article);
        $this->em->flush();

        self::assertSame([], $this->documents(), 'the premise: publishing never ran for the flush that deleted it');

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertContains('remove', array_map(
            static fn (array $d): mixed => $d['event'],
            $this->documents(),
        ), 'the deletion was dropped, so the history still shows a row that is gone');
    }

    public function testARemovalDraftedBeforeAFlushSurvivesTheLatePublicationOfAnother(): void
    {
        // $em->remove() fires preRemove where it is called, before any flush exists, so
        // the record it takes belongs to the flush about to run. If that flush's first
        // act is to find an earlier one's state behind it and publish it late, the
        // forgetting that follows used to take this with it -- the row was deleted and
        // the history said nothing at all about it.
        $this->em->persist($old = new Article('Edited earlier'));
        $this->em->persist($doomed = new Article('To be deleted'));
        $this->em->flush();

        $this->gateway->documents = [];

        $listener = $this->silenceOurPostFlush();
        $old->title = 'Edited earlier, by somebody else';
        $this->em->flush();
        $this->restorePostFlush($listener);

        // Drafted here, before the flush that will both publish the old record and do
        // the deleting.
        $this->em->remove($doomed);
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(Article::class)->findAll(), 'the premise: the row was deleted');

        self::assertSame(
            ['update', 'remove'],
            array_map(static fn (array $d): string => $d['event'], $this->documents()),
            'the deletion this flush carried out was forgotten with the records it published for the last one',
        );
    }

    public function testARemovalCalledOffIsNotCountedAsARecordBeingWrittenLate(): void
    {
        // A deletion the application changed its mind about, which is an ordinary thing
        // for a listener to do. preRemove has already taken the record; postRemove never
        // runs, because no row was deleted; so it stays where preRemove put it. It is not
        // one of the records being written late, though, because publish() never writes
        // one of those -- counting it made the warning name a record that was not going
        // anywhere.
        $this->em->persist($article = new Article('To be deleted'));
        $this->em->flush();

        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('Written'));
        $this->em->remove($article);
        $this->em->persist($article); // called off
        $this->em->flush();

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '1 audit record(s) are being written now, late'),
        ), sprintf("the warning counted a record that was never written; what was logged:\n%s", implode("\n", $this->logs)));
    }

    public function testTheDroppedWarningCountsWhatIsReallyDropped(): void
    {
        // The same sum on the other ending, where the records are not written at all.
        // "Nothing can vouch for this flush" is already the worst news in the file; it
        // has to come with the right number attached -- and a removal waiting for a flush
        // that has not happened yet is not part of it.
        $this->em->persist($article = new Article('To be deleted'));
        $this->em->flush();

        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('Collected'));
        $this->em->remove($article);
        $this->em->persist($article); // called off, so its record stays where preRemove put it
        $this->em->flush();

        // Let go of the entity before the manager is replaced. Measured, not assumed:
        // with the variable still held the old manager survives the collection and the
        // listener takes the other branch — the records are written late instead of
        // dropped — so the test would be asking about the wrong warning.
        unset($article);

        $this->reopen();
        $this->restorePostFlush($listener);
        gc_collect_cycles();

        $this->em->persist(new Article('Unrelated'));
        $this->em->flush();

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '1 audit record(s) it had collected are dropped'),
        ), sprintf("the warning counted a record nobody was dropping; what was logged:\n%s", implode("\n", $this->logs)));
    }

    public function testARemovalDraftedBeforeAFlushSurvivesTheDroppingOfAnother(): void
    {
        // The same at the other ending. A flush is refused in its own onFlush and the
        // application carries on: it removes something and flushes again. That flush's
        // first act is to find the refused one's state behind it and drop it, and the
        // record drafted for this one went with it -- the row was deleted with no history
        // of the deletion.
        $this->em->persist($doomed = new Article('To be deleted'));
        $this->em->persist($other = new Article('Something else'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            private bool $refused = false;

            public function onFlush(): void
            {
                if ($this->refused) {
                    return;
                }

                $this->refused = true;

                throw new \DomainException('this flush may not go through');
            }
        });

        $other->title = 'Something else, edited';

        try {
            $this->em->flush();
        } catch (\DomainException) {
            // what an application does when a listener refuses its flush
        }

        // Drafted after the refusal and before the flush that will carry it out.
        $this->em->remove($doomed);
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(Article::class)->findAll(), 'the premise: the row was deleted');

        self::assertContains(
            'remove',
            array_map(static fn (array $d): string => $d['event'], $this->documents()),
            'the deletion was dropped with the state of the flush that was refused',
        );
    }

    public function testAFlushWhoseManagerIsGoneHasItsRecordsDroppedAndSaysHowMany(): void
    {
        // The other ending. The records were collected, but the manager that would prove
        // they reached the database is closed or replaced — so nothing here can be shown
        // to have committed, and history that describes rows nobody has is worse than
        // history that is missing. It still has to be said out loud.
        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('Collected'));
        $this->em->persist(new Article('Also collected'));
        $this->em->flush();

        // What ManagerRegistry::resetManager() does, and the reason the flush's manager
        // is held by a weak reference at all: a fresh one takes over and the old one is
        // collected. close() would not do here — it clears, onClear drops everything the
        // flush collected, and there would be nothing left to warn about.
        $this->reopen();
        $this->restorePostFlush($listener);
        gc_collect_cycles();

        $this->em->persist(new Article('Unrelated'));
        $this->em->flush();

        self::assertSame(['Unrelated'], array_map(
            static fn (array $d): mixed => $d['changes']['title']['new'],
            $this->documents(),
        ), 'the records of a flush nothing can vouch for are not written under somebody else s operation');

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '2 audit record(s) it had collected are dropped'),
        ), sprintf("no warning said two records were dropped; what was logged:\n%s", implode("\n", $this->logs)));
    }

    public function testAFlushThatWasDroppedIsNotDroppedTwice(): void
    {
        // Forgetting is the point of that branch: the records are gone, and so is every
        // note about the flush that collected them. Left behind, they would be offered
        // to the flush after this one as well, and the warning would repeat for a flush
        // that ended two operations ago.
        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('Collected'));
        $this->em->flush();

        $this->reopen();
        $this->restorePostFlush($listener);
        gc_collect_cycles();

        $this->em->persist(new Article('First after'));
        $this->em->flush();

        $this->logs = [];

        $this->em->persist(new Article('Second after'));
        $this->em->flush();

        self::assertSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, 'dropped'),
        ), 'the flush was mourned twice');
    }

    /**
     * Takes this listener off postFlush and leaves it on everything else, which is what
     * a listener registered before it and throwing does to it — for one flush. The
     * caller puts it back, because the case under test is a flush that lost its
     * publishing and a process that carries on, not a process that never publishes
     * again.
     */
    public function testWhatADeadInnerFlushPlannedInsideACollectionIsNotPublished(): void
    {
        // The inner flush is refused in its own onFlush, so it never opened a
        // transaction and never wrote a row -- but by then it had already computed its
        // change sets, and what it planned to do inside a tracked collection was sitting
        // in the outer flush's state. The outer one committed, published everything it
        // found, and the history said a line went from 1 to 9 while the column still
        // held 1.
        [, $item] = $this->aCrateWithOneLine();
        $this->em->persist($article = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseTheSecondFlush();
        $this->changeAndFlushFromInside(static function () use ($item): void {
            $item->quantity = 9;
        });

        $article->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame(1, $this->quantityInTheDatabase($item), 'the premise: the refused flush wrote nothing');
        self::assertSame([], $this->linesRecordedFor('crate'), 'a line the refused flush only planned was recorded as history');
    }

    public function testWhatTheLiveFlushCollectedAboutTheSameLineSurvivesTheDeadOne(): void
    {
        // The same field, twice: the outer flush plans 1 -> 2 and carries it out, and the
        // inner one plans 2 -> 9 and dies. "items.1.quantity" names a column rather than
        // an occasion, so the second answer had simply written over the first -- and
        // taking the dead flush's work away has to put the live flush's answer back, not
        // leave the owner with nothing.
        [, $item] = $this->aCrateWithOneLine();
        $this->em->persist($article = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseTheSecondFlush();
        $this->changeAndFlushFromInside(static function () use ($item): void {
            $item->quantity = 9;
        }, after: $item);

        $item->quantity = 2;
        $article->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame(2, $this->quantityInTheDatabase($item), 'the premise: the live flush wrote its own value');
        self::assertSame(
            [['items.1.quantity' => ['old' => 1, 'new' => 2]]],
            $this->linesRecordedFor('crate'),
            'the history disagrees with the column',
        );
    }

    public function testAnInnerFlushThatRanItsStatementsKeepsItsHistoryWhenItsPostFlushIsSwallowed(): void
    {
        // The case that decides what "dead" means. This inner flush is not refused: it
        // runs, it commits, and somebody else's postFlush listener throws before ours is
        // reached. The column really moved, so the record has to stay -- which is why a
        // flush is judged by whether it ran statements and not by whether we saw it
        // finish.
        [, $item] = $this->aCrateWithOneLine();
        $this->em->persist($article = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $breaker = new class {
            public bool $armed = false;

            public function postFlush(): void
            {
                if ($this->armed) {
                    $this->armed = false;

                    throw new \DomainException('somebody else exploded in postFlush');
                }
            }
        };

        $ours = $this->silenceOurPostFlush();
        $this->em->getEventManager()->addEventListener([Events::postFlush], $breaker);
        $this->restorePostFlush($ours);

        $this->changeAndFlushFromInside(static function () use ($item, $breaker): void {
            $item->quantity = 9;
            $breaker->armed = true;
        });

        $article->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame(9, $this->quantityInTheDatabase($item), 'the premise: the inner flush wrote its row');
        self::assertSame(
            [['items.1.quantity' => ['old' => 1, 'new' => 9]]],
            $this->linesRecordedFor('crate'),
            'a flush that committed had its history thrown away because its postFlush was swallowed',
        );
    }

    public function testWhatADeadInnerFlushPlannedAndALaterOneCarriedOutIsRecordedByThatLaterOne(): void
    {
        // The other direction, and the one a fix for the first can get wrong: the change
        // the refused flush planned really does happen, a moment later, because the
        // application flushes again. Taking the dead flush's share away must not take the
        // live flush's answer about the same line with it.
        [, $item] = $this->aCrateWithOneLine();
        $this->em->persist($article = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseTheSecondFlush();

        $em = $this->em;

        $this->changeAndFlushFromInside(static function () use ($item): void {
            $item->quantity = 9;
        }, static function () use ($item, $em): void {
            // What an application does about a refusal it can recover from.
            $item->quantity = 3;
            $em->flush();
        });

        $article->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame(3, $this->quantityInTheDatabase($item), 'the premise: the second attempt went through');
        self::assertSame(
            [['items.1.quantity' => ['old' => 1, 'new' => 3]]],
            $this->linesRecordedFor('crate'),
            'the change the second attempt made was taken away with the first attempt',
        );
    }

    public function testTheFromSideIsWhatTheColumnHeldAndNotWhatARefusedFlushPlanned(): void
    {
        // Computing a change set is not free of consequence: Doctrine takes the new
        // values to be the entity's original data from then on. So a flush refused in its
        // own onFlush leaves the unit of work believing the row already holds what it was
        // about to write, and the flush that really carries the change out reports it as
        // starting from there. The column went straight from One to Three; the history
        // said it went from Two, a value it never held.
        $this->em->persist($subject = new Article('One'));
        $this->em->persist($trigger = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseTheSecondFlush();

        $em = $this->em;

        $this->changeAndFlushFromInside(static function () use ($subject): void {
            $subject->title = 'Two';
        }, static function () use ($subject, $em): void {
            $subject->title = 'Three';
            $em->flush();
        });

        $trigger->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame('Three', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$subject->id]), 'the premise: the column took the second attempt');

        $titles = [];

        foreach ($this->documents() as $document) {
            $titles[(string) $document['objectId']] = $document['changes']['title'];
        }

        self::assertSame(
            ['old' => 'One', 'new' => 'Three'],
            $titles[(string) $subject->id] ?? null,
            'the history starts the change from a value the column never held',
        );
    }

    public function testTheCorrectionIsSpentByTheFlushThatWritesAndNotKeptForTheNextOne(): void
    {
        // What a refused flush left behind is true of the column only until something
        // writes it. Here the second attempt goes through -- One to Three, correctly --
        // and then a third change follows it. That one starts from Three, which is what
        // the column now holds, and a correction still lying around would have started it
        // from One and claimed a step the row never took.
        $this->em->persist($subject = new Article('One'));
        $this->em->persist($trigger = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseTheSecondFlush();

        $em = $this->em;

        $this->changeAndFlushFromInside(static function () use ($subject): void {
            $subject->title = 'Two';
        }, static function () use ($subject, $em): void {
            $subject->title = 'Three';
            $em->flush();

            $subject->title = 'Four';
            $em->flush();
        });

        $trigger->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame('Four', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$subject->id]), 'the premise: the column took both attempts that went through');

        $steps = [];

        foreach ($this->documents() as $document) {
            if ((string) $document['objectId'] === (string) $subject->id) {
                $steps[] = [$document['changes']['title']['old'], $document['changes']['title']['new']];
            }
        }

        self::assertSame([['One', 'Three'], ['Three', 'Four']], $steps, 'the history does not read as one step after another');
    }

    public function testAFlushBeingDiscardedHandsForwardOnlyItsOwnChangeSets(): void
    {
        // The live flush has already written One -> Two and has the record to show for
        // it. Then an inner flush is refused, and a third change follows. Handing the
        // live flush's change set forward along with the refused one's would correct a
        // step that needs no correcting: the second record would start from One again,
        // and the history would read as two changes from the same value rather than one
        // after the other.
        $this->em->persist($subject = new Article('One'));
        $this->em->persist($trigger = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseTheSecondFlush();

        $em = $this->em;

        $this->changeAndFlushFromInside(static function (): void {
            // Nothing of its own: the refused flush is here only to be refused, with the
            // live flush's change set already taken and its record already built.
        }, static function () use ($subject, $em): void {
            $subject->title = 'Three';
            $em->flush();
        });

        $subject->title = 'Two';
        $trigger->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame('Three', $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$subject->id]), 'the premise: the column took both changes');

        $steps = [];

        foreach ($this->documents() as $document) {
            if ((string) $document['objectId'] === (string) $subject->id) {
                $steps[] = [$document['changes']['title']['old'], $document['changes']['title']['new']];
            }
        }

        self::assertSame([['One', 'Two'], ['Two', 'Three']], $steps, 'the history does not read as one step after another');
    }

    public function testTheContextBesideARecordIsNotWhatARefusedFlushPlannedForIt(): void
    {
        // The crate itself is not dirty in the live flush -- only a line inside it is --
        // so its record is assembled after the commit, and the always-recorded field
        // beside it is read from whatever change set the listener is holding for it. The
        // refused flush had left one: it planned to seal the crate and never did. The
        // record then carried "packed -> sealed" as the context of a change that was
        // about a quantity, and the column still said packed.
        [$crate, $item] = $this->aCrateWithOneLine();
        $this->em->persist($trigger = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseTheSecondFlush();
        $this->changeAndFlushFromInside(static function () use ($crate): void {
            $crate->status = 'sealed';
        });

        $item->quantity = 2;
        $trigger->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame('packed', $this->em->getConnection()->fetchOne('SELECT status FROM Crate WHERE code = ?', [$crate->code]), 'the premise: the refused flush never sealed anything');

        $crates = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'crate'));

        self::assertCount(1, $crates, 'the premise: the crate got a record for what happened inside it');
        self::assertSame(
            ['old' => 'packed', 'new' => 'packed'],
            $crates[0]['changes']['status'] ?? null,
            'the context beside the record is what a refused flush planned rather than what the column holds',
        );
    }

    /** @return array{0: Crate, 1: CrateItem} */
    private function aCrateWithOneLine(): array
    {
        $crate = new Crate('C-1');
        $crate->add($item = new CrateItem('SKU-1'));
        $this->em->persist($crate);

        return [$crate, $item];
    }

    private function quantityInTheDatabase(CrateItem $item): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT quantity FROM CrateItem WHERE id = ?', [$item->id]);
    }

    /**
     * What the history says happened inside a collection of that kind of owner, one entry
     * per document: the keys naming an element, which are the ones these tests are about.
     *
     * A list rather than one merged map. Merged, a first document saying the wrong thing
     * was covered by a second saying the right thing, and the assertion could not tell
     * "one correct record" from "a wrong record and a correction after it" — which is two
     * of the things this file exists to catch.
     *
     * @return list<array<string, mixed>>
     */
    private function linesRecordedFor(string $objectType): array
    {
        $documents = [];

        foreach ($this->documents() as $document) {
            if ($document['objectType'] !== $objectType) {
                continue;
            }

            $lines = [];

            foreach ($document['changes'] as $field => $change) {
                if (str_contains($field, '.')) {
                    $lines[$field] = $change;
                }
            }

            $documents[] = $lines;
        }

        return $documents;
    }

    /**
     * Runs something inside the preUpdate of one particular entity, once.
     *
     * preUpdate rather than postUpdate because it is the moment Doctrine will recompute
     * the change set after: what the listener leaves the entity holding is what the
     * statement writes. And of one particular entity because Doctrine groups its updates
     * by class and the order of the groups is not portable.
     */
    private function inThePreUpdateOf(object $entity, \Closure $what): void
    {
        $this->em->getEventManager()->addEventListener([Events::preUpdate], new class($this->em, $entity, $what) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly object $entity,
                private readonly \Closure $what,
            ) {
            }

            public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
            {
                if ($this->ran || $args->getObject() !== $this->entity) {
                    return;
                }

                $this->ran = true;
                ($this->what)($this->entity, $this->em);
            }
        });
    }

    private function refuseOneFlush(): void
    {
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            private bool $refused = false;

            public function onFlush(): void
            {
                if ($this->refused) {
                    return;
                }

                $this->refused = true;

                throw new \DomainException('this flush may not go through');
            }
        });
    }

    private function titleInTheDatabase(Article $article): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT title FROM Article WHERE id = ?', [$article->id]);
    }

    private function refuseTheSecondFlush(): void
    {
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            private int $seen = 0;

            public function onFlush(): void
            {
                if (++$this->seen === 2) {
                    throw new \DomainException('the inner flush is refused');
                }
            }
        });
    }

    /**
     * @param object|null $after the entity whose own update must have happened first,
     *        when the scenario depends on it. Doctrine groups its updates by class and
     *        the order of the groups is not something to build a test on: the same
     *        fixtures put a line's UPDATE before the entity that triggers this on one
     *        machine and after it on another, which is the difference between the column
     *        taking the live flush's value and taking the refused one's.
     */
    private function changeAndFlushFromInside(\Closure $change, ?\Closure $andThen = null, ?object $after = null): void
    {
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $change, $andThen, $after) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly \Closure $change,
                private readonly ?\Closure $andThen,
                private readonly ?object $after,
            ) {
            }

            public function postUpdate(\Doctrine\ORM\Event\PostUpdateEventArgs $args): void
            {
                if ($this->ran || ($this->after !== null && $args->getObject() !== $this->after)) {
                    return;
                }

                $this->ran = true;
                ($this->change)();

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    // what an application does when a listener refuses its inner flush
                }

                if ($this->andThen !== null) {
                    ($this->andThen)();
                }
            }
        });
    }

    public function testAnAbortedTopLevelFlushKeepsTheOriginalValueForItsRetry(): void
    {
        // No nesting at all, which is why this one matters most: an ordinary flush is
        // refused, the application catches it, changes the value again and flushes. The
        // refused flush had already moved Doctrine's idea of the original data, and the
        // correction that says otherwise was created by the discarding and then thrown
        // away two lines later by the forgetting that follows it -- so the retry recorded
        // a step from a value the column never held.
        $this->em->persist($article = new Article('One'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseOneFlush();

        $article->title = 'Two';

        try {
            $this->em->flush();
        } catch (\DomainException) {
            // what an application does when a listener refuses its flush
        }

        self::assertSame('One', $this->titleInTheDatabase($article), 'the premise: the refused flush wrote nothing');

        $article->title = 'Three';
        $this->em->flush();

        self::assertSame('Three', $this->titleInTheDatabase($article), 'the premise: the retry went through');

        self::assertSame(
            [['old' => 'One', 'new' => 'Three']],
            array_map(static fn (array $d): mixed => $d['changes']['title'], $this->documents()),
            'the history of the retry starts from a value the column never held',
        );
    }

    public function testTheCorrectionIsSpentFieldByFieldAndNotEntityByEntity(): void
    {
        // The refused flush planned two fields. The flush that recovers changes only one
        // of them, and the other is changed later still. Taking the whole entry when the
        // first was spent threw away a correction nobody had used, and the later change
        // started from the value the refused flush had planned.
        $this->em->persist($article = new Article('One'));
        $article->status = 'draft';
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseOneFlush();

        $article->title = 'Two';
        $article->status = 'sent';

        try {
            $this->em->flush();
        } catch (\DomainException) {
        }

        $article->status = 'archived';
        $this->em->flush();

        $article->title = 'Three';
        $this->em->flush();

        self::assertSame(
            [
                ['status' => ['old' => 'draft', 'new' => 'archived']],
                ['title' => ['old' => 'One', 'new' => 'Three']],
            ],
            array_map(
                static fn (array $d): array => array_filter(
                    $d['changes'],
                    static fn (array $c): bool => $c['old'] !== $c['new'],
                ),
                $this->documents(),
            ),
            'a correction nobody had used was thrown away with one that had been',
        );
    }

    public function testAnInnerFlushDoesNotRefileWhatTheOuterOneStillHasToWrite(): void
    {
        // A flush started from a lifecycle listener shares the outer flush's unit of work,
        // and getScheduledEntityUpdates() there still holds the outer flush's own
        // entities: executeUpdates() takes one off the list only after its statement. So
        // the inner flush's onFlush was handed rows it would never write, filed them under
        // its own number, and its discarding then threw away the correction the outer
        // flush had made and handed the outer's old side forward as if it were unwritten.
        $this->em->persist($subject = new Article('One'));
        $this->em->persist($trigger = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $refusals = new class {
            public int $seen = 0;

            public function onFlush(): void
            {
                // The first flush below is refused, and so is the inner one.
                if (++$this->seen === 1 || $this->seen === 3) {
                    throw new \DomainException('refused');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $refusals);

        $subject->title = 'Two';

        try {
            $this->em->flush();
        } catch (\DomainException) {
        }

        $subject->title = 'Three';

        $this->inThePreUpdateOf($trigger, static function (object $entity, EntityManagerInterface $em): void {
            try {
                $em->flush();
            } catch (\DomainException) {
            }
        });

        $trigger->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame('Three', $this->titleInTheDatabase($subject), 'the premise: the column took the second value');

        $titles = [];

        foreach ($this->documents() as $document) {
            $titles[(string) $document['objectId']] = $document['changes']['title'];
        }

        self::assertSame(
            ['old' => 'One', 'new' => 'Three'],
            $titles[(string) $subject->id] ?? null,
            'the inner flush took the outer one\'s correction away with its own discarding',
        );
    }

    public function testAnOuterUpdateAfterADeadInnerFlushRebuildsItsElementChanges(): void
    {
        // The inner flush is started from the element's own preUpdate, so what it plans
        // is what the statement would have written -- and it is refused. The handler then
        // puts a third value on the element and returns, Doctrine recomputes the change
        // set, and the outer flush writes that. The owner's history has to end up saying
        // what the column took, not what the outer flush planned before any of this.
        [, $item] = $this->aCrateWithOneLine();
        $this->em->flush();

        $this->gateway->documents = [];

        $this->refuseTheSecondFlush();
        $this->inThePreUpdateOf($item, static function (CrateItem $item, EntityManagerInterface $em): void {
            $item->quantity = 9;

            try {
                $em->flush();
            } catch (\DomainException) {
            }

            $item->quantity = 3;
        });

        $item->quantity = 2;
        $this->em->flush();

        self::assertSame(3, $this->quantityInTheDatabase($item), 'the premise: the column took the value the handler left');
        self::assertSame(
            [['items.1.quantity' => ['old' => 1, 'new' => 3]]],
            $this->linesRecordedFor('crate'),
            'the history says what the outer flush planned rather than what it wrote',
        );
    }

    public function testAnOuterWriteAfterASuccessfulInnerWriteWinsInTheOwnersHistory(): void
    {
        // The same shape with the inner flush living. It really writes 9, and then the
        // outer flush really writes 3 -- afterwards, although its number is lower, because
        // a number says when a flush BEGAN. Ordered by number the inner flush's earlier
        // value won, and the owner's history ended at a value the column no longer held.
        [, $item] = $this->aCrateWithOneLine();
        $this->em->flush();

        $this->gateway->documents = [];

        $seen = [];

        $this->inThePreUpdateOf($item, static function (CrateItem $item, EntityManagerInterface $em) use (&$seen): void {
            $item->quantity = 9;
            $em->flush();

            $seen[] = (int) $em->getConnection()->fetchOne('SELECT quantity FROM CrateItem WHERE id = ?', [$item->id]);

            $item->quantity = 3;
        });

        $item->quantity = 2;
        $this->em->flush();

        self::assertSame([9], $seen, 'the premise: the inner flush wrote its own value first');
        self::assertSame(3, $this->quantityInTheDatabase($item), 'the premise: the outer flush wrote after it');
        self::assertSame(
            [['items.1.quantity' => ['old' => 1, 'new' => 3]]],
            $this->linesRecordedFor('crate'),
            'the owner\'s history ends at the value the inner flush wrote rather than the one the column holds',
        );
    }

    public function testAnEmptyingWhoseOwnPostFlushWasSwallowedIsStillWrittenLate(): void
    {
        // A join table emptied, which raises no entity event at all: no insert, no update,
        // no removal, so nothing says the flush reached its statements. The question that
        // stands in for it used to be the unit of work's schedule -- and a postFlush
        // listener that throws leaves every schedule exactly as a refusal would, because
        // UnitOfWork::commit() calls postCommitCleanup() after dispatching postFlush with
        // no try/finally between them. The rows were deleted and the history was dropped
        // with the warning that says nothing could vouch for the flush.
        $route = new Route('R-1');
        $route->stops->add($first = new Stop('Alpha'));
        $route->stops->add($second = new Stop('Beta'));
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $breaker = new class {
            public bool $armed = false;

            public function postFlush(): void
            {
                if ($this->armed) {
                    $this->armed = false;

                    throw new \DomainException('somebody else exploded in postFlush');
                }
            }
        };

        $ours = $this->silenceOurPostFlush();
        $this->em->getEventManager()->addEventListener([Events::postFlush], $breaker);
        $this->restorePostFlush($ours);

        $route->stops->clear();
        $breaker->armed = true;

        try {
            $this->em->flush();
        } catch (\DomainException) {
            // the application copes
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM route_stop'), 'the premise: the rows were deleted');

        // Somebody else's operation, which is what carries the old records out.
        $this->em->persist(new Article('Bob writes something'));
        $this->em->flush();

        $routes = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'route'));

        self::assertCount(1, $routes, 'the emptying was dropped, or recorded more than once');
        self::assertSame(['old' => ['Alpha', 'Beta'], 'new' => []], $routes[0]['changes']['stops'] ?? null);
    }

    public function testTwoRefusalsInARowLeaveBothTheirCorrectionsBehind(): void
    {
        // The first flush plans a title and is refused; the second plans a status and is
        // refused; the third changes the title again. Each discarding hands forward what
        // it found the row holding, and the second must add to the first rather than
        // replace it -- the two are about different columns, and the column the first one
        // knew about is still holding what it said.
        $this->em->persist($article = new Article('One'));
        $article->status = 'draft';
        $this->em->flush();

        $this->gateway->documents = [];

        $refusals = new class {
            public int $seen = 0;

            public function onFlush(): void
            {
                if (++$this->seen <= 2) {
                    throw new \DomainException('refused');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $refusals);

        $article->title = 'Two';

        try {
            $this->em->flush();
        } catch (\DomainException) {
        }

        $article->status = 'sent';

        try {
            $this->em->flush();
        } catch (\DomainException) {
        }

        self::assertSame('One', $this->titleInTheDatabase($article), 'the premise: neither refusal wrote anything');

        $article->title = 'Three';
        $this->em->flush();

        self::assertSame(
            [['old' => 'One', 'new' => 'Three']],
            array_map(static fn (array $d): mixed => $d['changes']['title'], $this->documents()),
            'the second refusal replaced what the first one knew instead of adding to it',
        );
    }

    public function testTwoFlushesEachWithNewsAboutADifferentLineBothReachTheRecord(): void
    {
        // Two buckets, and a key in each that the other does not have. Reading them as one
        // answer has to keep both: the merge exists to say which flush's answer about the
        // SAME key is current, and a key only one of them has is not that question.
        $crate = new Crate('C-1');
        $crate->add($first = new CrateItem('SKU-1'));
        $crate->add($second = new CrateItem('SKU-2'));
        $this->em->persist($crate);
        $this->em->persist($trigger = new Article('Trigger'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->inThePreUpdateOf($trigger, static function (object $entity, EntityManagerInterface $em) use ($second): void {
            $second->quantity = 7;
            $em->flush();
        });

        $first->quantity = 2;
        $trigger->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame(2, $this->quantityInTheDatabase($first), 'the premise: the outer flush wrote its line');
        self::assertSame(7, $this->quantityInTheDatabase($second), 'the premise: the inner flush wrote the other one');

        $lines = $this->linesRecordedFor('crate');

        self::assertCount(1, $lines, 'the crate got more than one record for one operation');

        ksort($lines[0]);

        self::assertSame(
            [
                'items.'.$first->id.'.quantity' => ['old' => 1, 'new' => 2],
                'items.'.$second->id.'.quantity' => ['old' => 1, 'new' => 7],
            ],
            $lines[0],
            'one of the two flushes\' news about the collection did not reach the record',
        );
    }

    private function silenceOurPostFlush(): AuditSubscriber
    {
        foreach ($this->em->getEventManager()->getListeners(Events::postFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                $this->em->getEventManager()->removeEventListener([Events::postFlush], $listener);

                return $listener;
            }
        }

        self::fail('no audit listener was attached to postFlush');
    }

    private function restorePostFlush(AuditSubscriber $listener): void
    {
        $this->em->getEventManager()->addEventListener([Events::postFlush], $listener);
    }
}
