<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Catalogue;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * What a history may say about a line when the collection holding it is emptied.
 *
 * Every test here is one sequence — six a generator found and four a reviewer named — and
 * all of them are the same question asked ten ways: the listener is told things by
 * Doctrine, and the rows are what actually happened. The two part company in more places
 * than they look like they should — a collection remembering a membership from a moment
 * that has passed, a change set left behind by a flush that was refused or whose
 * publishing somebody swallowed, an UPDATE Doctrine reports for a row a DELETE has just
 * taken, an object whose fields Doctrine will not overwrite with what it just read.
 *
 * The generated ones are written out rather than left to the generator because a generated
 * sequence is a search and a test is a guard: the search runs a thousand of them and
 * cannot say which line it was about, and these say it. Each one here was watched failing
 * against its own fix taken out again.
 *
 * @see WhatTheHistorySaysAgainstWhatTheRowsDidTest for the search
 */
final class WhatAnEmptiedCollectionSaysAboutItsLinesTest extends DoctrineTestCase
{
    public function testALineThatLeftEarlierIsNotLostAgainWhenTheCollectionIsReplaced(): void
    {
        // The membership has to come from the rows. Doctrine's snapshot of the collection
        // is what it held when it was last synchronised, and moving a line by its own side
        // leaves this collection clean — so nothing re-snapshots it, and the line it no
        // longer holds is still in there when the replacement asks.
        [$crate, $other, $first] = $this->twoCratesAndALine();

        $first->crate = $other;
        $this->em->flush();

        $this->gateway->documents = [];

        $crate->items = new ArrayCollection();
        $this->em->flush();

        self::assertContains('C-1 items lost SKU-2', $this->everyStatement(), 'the line whose row this DELETE takes');
        self::assertNotContains('C-1 items lost SKU-1', $this->everyStatement(), 'the line that left two flushes ago');
    }

    public function testALineIsNotTakenOutOfTheEmptyingByAnArrivalThatLooksLikeIt(): void
    {
        // The whole-collection form leaves out what the element form already said, and it
        // matches by what an element is SHOWN as -- it has to, because a line the flush
        // deleted has had its identifier cleared by then. So two different lines with the
        // same name are the same line to it, and what it can say twice is a DEPARTURE:
        // counting an ARRIVAL as already said took a line out of the emptying because a
        // different line with its name was joining, and the row went with nothing said.
        //
        // One record, one flush: the crate is emptied and a namesake moves in at once.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $this->em->persist($other = new Crate('C-2'));

        $crate->add(new CrateItem('SKU-2'));
        $other->add($namesake = new CrateItem('SKU-2'));

        $this->em->flush();
        $this->gateway->documents = [];

        $namesake->crate = $crate;
        $crate->items = new ArrayCollection([$namesake]);
        $this->em->flush();

        self::assertContains('C-1 items lost SKU-2', $this->everyStatement(), 'the line whose row this DELETE took');
    }

    public function testADepartureAfterARefusedMoveNamesTheCrateTheRowWasIn(): void
    {
        // A refusal is the one event that moves Doctrine's record of the row without
        // moving the row: computing the change set refreshed the original data to the
        // values it read off the object, and then nothing was written. What this listener
        // kept — the side the column still holds — is the only thing that still knows.
        [$crate, $other, $first] = $this->twoCratesAndALine();

        $first->crate = $other;
        $this->flushRefused();

        $this->gateway->documents = [];

        $crate->items->clear();
        $this->em->flush();

        self::assertContains('C-1 items lost SKU-1', $this->everyStatement(), 'the crate whose row went');
        self::assertNotContains('C-2 items lost SKU-1', $this->everyStatement(), 'not the crate it was going to');
    }

    public function testADepartureAfterAMoveThatWasWrittenNamesTheCrateItMovedTo(): void
    {
        // The mirror of the one above, and the reason the order of the two readers is not
        // a matter of taste. Here the move WAS written; what was not cleared is the unit
        // of work's change set, because postCommitCleanup() is what clears one and a
        // postFlush listener that throws stops it running. Read from there, the departure
        // went to the crate the line had already left.
        [$crate, $other, $first] = $this->twoCratesAndALine();

        $first->crate = $other;
        $this->flushWithTheirPostFlushThrowing();

        $this->gateway->documents = [];

        $crate->items->clear();
        $this->em->flush();

        $said = $this->everyStatement();

        self::assertContains('C-2 items lost SKU-1', $said, 'the crate whose row went');

        // Once, by the move itself, which really did take the line out of this crate --
        // and not a second time by the deletion, which is what reading the departure from
        // a change set that had already been written produced.
        self::assertCount(1, array_keys($said, 'C-1 items lost SKU-1', true), 'the move, and nothing else');
    }

    public function testALineWhoseRowTheEmptyingTakesArrivesNowhere(): void
    {
        // Doctrine carries out a collection's deletion before it writes entity updates, so
        // the UPDATE moving this line matches nothing. Doctrine reports the move all the
        // same — its state has the line alive under the other crate, the database has no
        // line at all — and the arrival was written against a crate that never held it.
        [$crate, $other, $first] = $this->twoCratesAndALine();

        $this->gateway->documents = [];

        $first->crate = $other;
        $crate->items = new ArrayCollection();
        $this->em->flush();

        self::assertContains('C-1 items lost SKU-1', $this->everyStatement(), 'the row did go, and from here');
        self::assertNotContains('C-2 items gained SKU-1', $this->everyStatement(), 'and it arrived nowhere');
    }

    public function testALineWhoseRowTheEmptyingTakesDidNotChangeInside(): void
    {
        // The same UPDATE and the same nothing, for a line that was edited rather than
        // moved: a column recorded as moving from one value to another in a row that was
        // being deleted in the same flush.
        [$crate, , $first] = $this->twoCratesAndALine();

        $this->gateway->documents = [];

        $first->quantity = 2;
        $crate->items = new ArrayCollection();
        $this->em->flush();

        foreach ($this->documents() as $document) {
            foreach (array_keys($document['changes'] ?? []) as $name) {
                self::assertStringNotContainsString('.quantity', (string) $name, 'a row on its way out did not change');
            }
        }
    }

    public function testALineWhoseRowHadNoOwnerDepartsFromNobody(): void
    {
        // "Nobody" is an answer, and the reader that has it is asked whether it HAS one.
        // A line saved with no crate at all, offered one by a flush that was then refused,
        // and deleted afterwards: what this listener kept is a null, and reading that as
        // silence sent the question on to Doctrine's original data -- which the refused
        // flush had already moved to the crate the line never reached.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $this->em->persist($line = new CrateItem('SKU-1'));
        $this->em->flush();

        self::assertNull($this->em->getConnection()->fetchOne('SELECT crate_id FROM CrateItem'), 'the premise: the row has no owner');

        $line->crate = $crate;
        $this->flushRefused();

        self::assertNull($this->em->getConnection()->fetchOne('SELECT crate_id FROM CrateItem'), 'and the refusal left it that way');

        $this->gateway->documents = [];

        $this->em->remove($line);
        $this->em->flush();

        self::assertSame([], $this->everyStatement(), 'no collection lost a line it never held');
    }

    public function testTwoNamesakesLeavingByDifferentRoadsAreBothRecorded(): void
    {
        // Two lines of one crate may carry the same name, and the whole-collection form
        // leaves out what the element form already said. Matched by what an element is
        // shown as, one departure named for one of them took both out of the emptying and
        // a row went with nothing said about it. The identifiers are read in onFlush, where
        // they are still there, and the two roads meet on those instead.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($first = new CrateItem('DUP'));
        $crate->add(new CrateItem('DUP'));
        $this->em->flush();

        $this->gateway->documents = [];

        $this->em->remove($first);              // one leaves by its own removal
        $crate->items = new ArrayCollection();  // the other by the emptying
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem'), 'both rows went');
        self::assertCount(2, array_keys($this->everyStatement(), 'C-1 items lost DUP', true), 'and both were said');
    }

    public function testDeletingJoinRowsLeavesTheLinesOwnChangeAlone(): void
    {
        // Emptying an owning many-to-many deletes JOIN rows. Every line stays exactly where
        // it was, alive and writable, so an UPDATE of one after that deletion reaches its
        // row -- and the rule that drops what a flush said about a line whose row is going
        // does not apply here at all. Applied anyway, it threw away a change that happened.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($line = new CrateItem('SKU-1'));
        $this->em->persist($catalogue = new Catalogue('CAT'));
        $this->em->flush();

        $catalogue->items->add($line);
        $this->em->flush();

        $this->gateway->documents = [];

        $line->quantity = 9;
        $catalogue->items->clear();
        $this->em->flush();

        self::assertSame(9, (int) $this->em->getConnection()->fetchOne('SELECT quantity FROM CrateItem'), 'the row is alive and changed');

        $changes = array_merge(...array_values(array_map(
            static fn (array $document): array => $document['changes'] ?? [],
            $this->documents(),
        )));

        self::assertArrayHasKey('items.'.$line->id.'.quantity', $changes, 'the change the row really took');
        self::assertContains('CAT items lost SKU-1@C-1', $this->everyStatement(), 'and the join rows that really went');
    }

    public function testAnEmptyingNamesTheStoredValueRatherThanAnUnwrittenRename(): void
    {
        // Reading the rows says WHICH elements the DELETE takes, not what they hold:
        // Doctrine hands back the objects it already has rather than overwriting their
        // fields with what the SELECT read. A line renamed and never written -- the
        // collection's DELETE takes its row first, so the UPDATE finds nothing -- was named
        // in the history of its own deletion by a value no row ever held.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($line = new CrateItem('SKU-OLD'));
        $this->em->flush();

        $this->gateway->documents = [];

        $line->sku = 'SKU-NEVER-WRITTEN';
        $crate->items = new ArrayCollection();
        $this->em->flush();

        $said = $this->everyStatement();

        self::assertContains('C-1 items lost SKU-OLD', $said, 'what the row held');
        self::assertNotContains('C-1 items lost SKU-NEVER-WRITTEN', $said, 'and not what it was about to');
    }

    public function testAMoveAndAClearInOneFlushNameTheCrateTheRowWasIn(): void
    {
        // Two readers say the same thing here and in the test above, and the truth is the
        // opposite in each: change set [C-1, C-2] with original data C-2. There the move
        // was written and the row is under C-2; here the clear takes the row before the
        // UPDATE can, so it is under C-1 — and computeChangeSets() has already moved the
        // original data to what it read off the object. Nothing in memory tells the two
        // apart, so the row is asked, and only where the two readers contradict.
        [$crate, $other, $first] = $this->twoCratesAndALine();

        $this->gateway->documents = [];

        $first->crate = $other;   // re-pointed, not yet written
        $crate->items->clear();   // and the crate emptied in the same flush
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem'), 'both rows went, and from C-1');

        $said = $this->everyStatement();

        self::assertContains('C-1 items lost SKU-1', $said, 'the crate whose row went');
        self::assertNotContains('C-2 items lost SKU-1', $said, 'not the crate it was pointed at');
    }

    public function testALinePutBackWhereItsRowAlreadyIsMovesNowhere(): void
    {
        // A refusal leaves Doctrine believing the line moved while the row never did, so
        // putting it back is an UPDATE that moves nothing. Doctrine reports the change
        // because its own idea of the row went away and came back; the corrected old side
        // and the new side are the same crate, and a crate that both lost and gained the
        // line under one key kept whichever was written second.
        [$crate, $other, $first] = $this->twoCratesAndALine();

        $first->crate = $other;
        $this->flushRefused();

        self::assertSame('C-1', (string) $this->em->getConnection()->fetchOne('SELECT crate_id FROM CrateItem WHERE sku = ?', ['SKU-1']), 'the premise: the row never moved');

        $this->gateway->documents = [];

        $first->crate = $crate;
        $this->em->flush();

        self::assertSame([], $this->everyStatement(), 'nothing came and nothing went');
    }

    public function testAChangeInsideALineWhoseRowHasNoOwnerBelongsToNoCrate(): void
    {
        // The same reader, on the road an ordinary change takes. Nothing about the
        // association changed in this flush — the line is here because its quantity did —
        // so the object is normally right about who owns it. It is wrong after a refusal:
        // the flush that offered this line a crate never wrote anything, and the change
        // inside it was recorded against a collection that has never held it.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $this->em->persist($line = new CrateItem('SKU-LOOSE'));
        $this->em->flush();

        $line->crate = $crate;
        $this->flushRefused();

        self::assertNull($this->em->getConnection()->fetchOne('SELECT crate_id FROM CrateItem'), 'the premise: the row has no owner');

        $this->gateway->documents = [];

        $line->quantity = 2;
        $this->em->flush();

        self::assertSame([], $this->documents(), 'a change inside a line no crate owns belongs to no crate');
    }

    public function testALineThatArrivedAndLeftInsideOneOperationIsNotHistory(): void
    {
        // The same operation, read for what it says about the line that came and went.
        // Its row was in the table between two statements nobody outside the operation
        // could see, and the record is about the operation: nothing happened to it.
        //
        // The sweep that drops such statements used to run once, where the emptying is
        // collected — and a flush nested inside another writes its arrival afterwards, so
        // the order decided whether the sweep saw anything. It is kept as a set now and
        // asked whenever something new is about to be written down.
        [$crate] = $this->twoCratesAndALine();
        $this->em->persist($article = new Article('One'));
        $this->em->flush();

        $this->gateway->documents = [];

        $crate->items = new ArrayCollection();
        $crate->add(new CrateItem('SKU-NEW'));

        $this->flushWithOneNestedInside($article);

        $said = $this->everyStatement();

        self::assertNotContains('C-1 items gained SKU-NEW', $said, 'it never settled anywhere');
        self::assertNotContains('C-1 items lost SKU-NEW', $said, 'so it left nowhere either');
    }

    /**
     * A flush with another one started from inside it, which is what puts two flushes of
     * one operation in front of the same schedule.
     */
    private function flushWithOneNestedInside(Article $article): void
    {
        $inner = new class($this->em, $article) {
            public bool $ran = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly Article $article,
            ) {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;
                $this->article->title = 'from inside';   // something for the inner flush to write
                $this->em->flush();
            }
        };

        $this->em->getEventManager()->addEventListener([Events::preUpdate], $inner);

        try {
            $this->em->flush();
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::preUpdate], $inner);
        }

        self::assertTrue($inner->ran, 'the premise: a flush really did start inside this one');
    }

    public function testALineAnInnerFlushInsertedAndAnOuterDeletionTookNeverLeftAnything(): void
    {
        // Written out from a generated sequence rather than composed, because every
        // arrangement composed in a readable order was covered by the sweep instead. The
        // order is what matters: the emptying that names this line is collected AFTER the
        // sweep has already dropped its arrival, by a later flush that reads the rows and
        // finds the line still in them.
        [$crate, $other, $first] = $this->twoCratesAndALine();
        $this->em->persist($article = new Article('One'));
        $this->em->flush();

        $this->gateway->documents = [];

        $first->crate = $other;
        $this->flushWithOneNestedInside($article);

        $crate->items = new ArrayCollection();
        $this->flushWithTheirPostFlushThrowing();

        $article->title = 'edited';
        $crate->items = new ArrayCollection();
        $crate->add(new CrateItem('SKU-NEW'));
        $this->flushWithOneNestedInside($article);

        $this->em->flush();

        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem WHERE sku = ?', ['SKU-NEW']),
            'the premise: no row of it survived',
        );

        $said = $this->everyStatement();

        self::assertNotContains('C-1 items lost SKU-NEW', $said, 'a line that never settled did not leave');
        self::assertNotContains('C-1 items gained SKU-NEW', $said, 'nor arrive');
    }

    public function testWhatIsShownOfADeletedLineCanStillReadItsOwnAssociations(): void
    {
        // Showing an element as the ROW has it means building a copy carrying the stored
        // values, and a representer is ordinary application code: it may read the line's
        // own crate as readily as its own column. A copy with the columns put back and the
        // associations left empty answers "nowhere" to a representer that asks — which is
        // a value no row ever held, by the machinery built to stop exactly that.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($line = new CrateItem('SKU-1'));
        $this->em->persist($catalogue = new Catalogue('CAT'));
        $this->em->flush();

        $catalogue->items->add($line);
        $this->em->flush();

        $this->gateway->documents = [];

        // Unsaved, so the copy is built at all: an element nobody touched is shown as it
        // stands, and nothing about associations comes into it.
        $line->sku = 'SKU-NEVER-WRITTEN';
        $catalogue->items->clear();
        $this->em->flush();

        $said = $this->everyStatement();

        self::assertContains('CAT items lost SKU-1@C-1', $said, 'the stored name, and the crate the row points at');
        self::assertNotContains('CAT items lost SKU-1@nowhere', $said, 'not a copy with its associations dropped');
    }

    public function testAnEmptyingAfterARefusedRenameNamesTheStoredName(): void
    {
        // The other reader of the same copy. A refusal is the one event that moves
        // Doctrine's record of a row without moving the row: the change set is computed,
        // the original data is overwritten with it, and then nothing is written. By the
        // next flush the element is not even dirty — original and object agree on a value
        // the column has never held — so the change set says nothing and what this
        // listener kept is the only thing that does.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($line = new CrateItem('SKU-OLD'));
        $this->em->flush();

        $line->sku = 'SKU-REFUSED';
        $this->flushRefused();

        self::assertSame('SKU-OLD', (string) $this->em->getConnection()->fetchOne('SELECT sku FROM CrateItem'), 'the premise: the column never moved');

        $this->gateway->documents = [];

        $crate->items = new ArrayCollection();
        $this->em->flush();

        $said = $this->everyStatement();

        self::assertContains('C-1 items lost SKU-OLD', $said, 'what the column held');
        self::assertNotContains('C-1 items lost SKU-REFUSED', $said, 'not what a refused flush wanted it to hold');
    }

    /**
     * Two crates and two lines, everything flushed and the history cleared.
     *
     * Both lines start in the first crate unless the second is asked to start in the
     * other one, which is what a test about a line ARRIVING needs.
     *
     * @return array{Crate, Crate, CrateItem, CrateItem}
     */
    private function twoCratesAndALine(bool $theSecondLineStartsInTheOtherCrate = false): array
    {
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($crate = new Crate('C-1'));
        $this->em->persist($other = new Crate('C-2'));

        $crate->add($first = new CrateItem('SKU-1'));
        ($theSecondLineStartsInTheOtherCrate ? $other : $crate)->add($second = new CrateItem('SKU-2'));

        $this->em->flush();
        $this->gateway->documents = [];

        return [$crate, $other, $first, $second];
    }

    /**
     * Every change in every document, as "<owner> <field> gained|lost <element>".
     *
     * Both forms of a collection change reduced to the same sentence, because which of
     * them the bundle used is not what any of these tests is about.
     *
     * @return list<string>
     */
    private function everyStatement(): array
    {
        $said = [];

        foreach ($this->documents() as $document) {
            $owner = (string) ($document['objectId'] ?? '?');

            foreach ($document['changes'] ?? [] as $name => $change) {
                $name = (string) $name;
                $field = str_contains($name, '.') ? substr($name, 0, (int) strpos($name, '.')) : $name;

                foreach (self::asMembers($change['old'] ?? null) as $gone) {
                    if (!\in_array($gone, self::asMembers($change['new'] ?? null), true)) {
                        $said[] = sprintf('%s %s lost %s', $owner, $field, $gone);
                    }
                }

                foreach (self::asMembers($change['new'] ?? null) as $came) {
                    if (!\in_array($came, self::asMembers($change['old'] ?? null), true)) {
                        $said[] = sprintf('%s %s gained %s', $owner, $field, $came);
                    }
                }
            }
        }

        return $said;
    }

    /**
     * @return list<string>
     */
    private static function asMembers(mixed $side): array
    {
        if (\is_array($side)) {
            return array_values(array_map(strval(...), $side));
        }

        return \is_string($side) ? [$side] : [];
    }

    private function flushRefused(): void
    {
        $veto = new class {
            public function onFlush(): void
            {
                throw new \DomainException('this flush is refused');
            }
        };

        $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

        try {
            $this->em->flush();
        } catch (\DomainException) {
            // what an application does about a listener that refuses its flush
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $veto);
        }
    }

    private function flushWithTheirPostFlushThrowing(): void
    {
        $breaker = new class {
            public function postFlush(): void
            {
                throw new \DomainException('somebody else exploded in postFlush');
            }
        };

        $ours = [];

        foreach ($this->em->getEventManager()->getListeners(Events::postFlush) as $listener) {
            $ours[] = $listener;
            $this->em->getEventManager()->removeEventListener([Events::postFlush], $listener);
        }

        // Theirs first, so it throws before this listener's own postFlush is reached:
        // UnitOfWork::commit() dispatches postFlush and calls postCommitCleanup()
        // afterwards with nothing between them, so everything Doctrine scheduled stays
        // scheduled and every change set it computed stays computed.
        $this->em->getEventManager()->addEventListener([Events::postFlush], $breaker);

        foreach ($ours as $listener) {
            $this->em->getEventManager()->addEventListener([Events::postFlush], $listener);
        }

        try {
            $this->em->flush();
        } catch (\DomainException) {
            // the application's own listener exploding is not this listener's business
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::postFlush], $breaker);
        }
    }
}
