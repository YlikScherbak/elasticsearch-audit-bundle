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

    public function testARemovalThatWasCalledOffIsStillOneOfTheRecordsCountedAndCarried(): void
    {
        // A deletion the application changed its mind about, which is an ordinary thing
        // for a listener to do. preRemove has already taken the record; postRemove never
        // runs, because no row was deleted; so it stays where preRemove put it while
        // everything else the flush wrote sits in the other list.
        //
        // That is the only state in which the two lists are both occupied, and it is
        // what the guard and the count are written for. Read as one list, the flush is
        // reported at half its size and — worse — a flush whose *only* record is in the
        // other one is read as having collected nothing and is dropped.
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
            static fn (string $line): bool => str_contains($line, '2 audit record(s) are being written now, late'),
        ), sprintf("the warning counted one of the two lists; what was logged:
%s", implode("
", $this->logs)));
    }

    public function testTheDroppedWarningCountsBothListsToo(): void
    {
        // The same sum on the other ending, where the records are not written at all.
        // "Nothing can vouch for this flush" is already the worst news in the file; it
        // has to come with the right number attached.
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
            static fn (string $line): bool => str_contains($line, '2 audit record(s) it had collected are dropped'),
        ), sprintf("no warning said two records were dropped; what was logged:
%s", implode("
", $this->logs)));
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
        });

        $item->quantity = 2;
        $article->title = 'Trigger, edited';
        $this->em->flush();

        self::assertSame(2, $this->quantityInTheDatabase($item), 'the premise: the live flush wrote its own value');
        self::assertSame(
            ['items.1.quantity' => ['old' => 1, 'new' => 2]],
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
            ['items.1.quantity' => ['old' => 1, 'new' => 9]],
            $this->linesRecordedFor('crate'),
            'a flush that committed had its history thrown away because its postFlush was swallowed',
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
     * What the history says happened inside a collection of that kind of owner: the keys
     * naming an element, which are the ones these tests are about.
     *
     * @return array<string, mixed>
     */
    private function linesRecordedFor(string $objectType): array
    {
        $lines = [];

        foreach ($this->documents() as $document) {
            if ($document['objectType'] !== $objectType) {
                continue;
            }

            foreach ($document['changes'] as $field => $change) {
                if (str_contains($field, '.')) {
                    $lines[$field] = $change;
                }
            }
        }

        return $lines;
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

    private function changeAndFlushFromInside(\Closure $change, ?\Closure $andThen = null): void
    {
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class($this->em, $change, $andThen) {
            private bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly \Closure $change,
                private readonly ?\Closure $andThen,
            ) {
            }

            public function postUpdate(): void
            {
                if ($this->ran) {
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
