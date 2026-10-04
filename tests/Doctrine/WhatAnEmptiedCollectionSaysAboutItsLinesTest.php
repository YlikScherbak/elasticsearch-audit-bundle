<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Bin;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\BinItem;
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

    public function testALineThatArrivedAndLeftInsideOneOperationIsBothFacts(): void
    {
        // The same operation, read for what it says about the line that came and went.
        // It used to say nothing -- the record was about the operation, and over the
        // operation nothing happened to the line -- which took a sweep across flushes that
        // five rounds kept finding holes in. Decision A: the history says what each flush
        // did, and the line's row was inserted by one statement and taken by another. Both
        // are said, once. The operation as one answer is what a frame is for.
        [$crate] = $this->twoCratesAndALine();
        $this->em->persist($article = new Article('One'));
        $this->em->flush();

        $this->gateway->documents = [];

        $crate->items = new ArrayCollection();
        $crate->add(new CrateItem('SKU-NEW'));

        $this->flushWithOneNestedInside($article);

        $said = $this->everyStatement();

        self::assertSame(1, \count(array_keys($said, 'C-1 items gained SKU-NEW', true)), 'it arrived, once');
        self::assertSame(1, \count(array_keys($said, 'C-1 items lost SKU-NEW', true)), 'and left, once');
    }

    /**
     * A flush with another one started from inside it, which is what puts two flushes of
     * one operation in front of the same schedule.
     */
    public function testACollectionNothingLoadedIsEmptiedLineByLineWithoutLoadingIt(): void
    {
        // The emptying is one DELETE of every row the crate holds, and what it took is only
        // as known as those rows are: the application never loaded them. So they are read
        // once, before the DELETE -- as rows, through the connection. Loading the collection
        // would have put two entities into the application's identity map and run its
        // postLoad listeners on them: the application's code, on the bundle's account.
        $this->em->persist($crate = new Crate('C-9'));
        $crate->add(new CrateItem('SKU-A'));
        $crate->add(new CrateItem('SKU-B'));
        $this->em->flush();
        $this->em->clear();

        $loaded = new \ArrayObject();
        $this->em->getEventManager()->addEventListener([Events::postLoad], new class($loaded) {
            public function __construct(private readonly \ArrayObject $loaded)
            {
            }

            public function postLoad(\Doctrine\ORM\Event\PostLoadEventArgs $args): void
            {
                if ($args->getObject() instanceof CrateItem) {
                    $this->loaded[] = $args->getObject()->sku;
                }
            }
        });

        $crate = $this->em->find(Crate::class, 'C-9');
        self::assertNotNull($crate);
        self::assertInstanceOf(\Doctrine\ORM\PersistentCollection::class, $crate->items);
        self::assertFalse($crate->items->isInitialized(), 'the premise: nothing loaded the lines');

        $this->gateway->documents = [];
        $this->queries = [];

        $crate->items = new ArrayCollection();
        $this->em->flush();
        // Doctrine itself touches the replaced collection once more after its DELETE -- the
        // update of the crate reads an offset of the change it was handed -- and finds nothing:
        // that SELECT is Doctrine's, with or without the bundle. What the bundle asks is asked
        // before the DELETE.
        $ran = \array_slice($this->queries, 0, (int) array_search('DELETE FROM CrateItem WHERE crate_id = ?', $this->queries, true));

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem WHERE crate_id = ?', ['C-9']), 'the premise: the rows went');
        self::assertSame([['items' => ['old' => ['SKU-A', 'SKU-B'], 'new' => []]]], $this->theCollectionsChanges());
        self::assertSame([], $loaded->getArrayCopy(), 'no line was loaded to say so');
        self::assertCount(1, array_filter($ran, static fn (string $sql): bool => str_starts_with($sql, 'SELECT')), 'the rows were read once before they went, and nothing else was asked');
        self::assertSame(['SELECT id, sku, quantity, crate_id FROM CrateItem WHERE crate_id = ?'], $ran, 'as rows, not as entities');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whetherAFlushRanBefore(): iterable
    {
        yield 'the first flush the listener sees' => ['none'];
        yield 'after another flush, settled' => ['settled'];
        yield 'after another flush in the application\'s transaction, its replay still running' => ['in a transaction'];
    }

    /**
     * A crate's lines read as rows before its emptying, one of them already remembered and one
     * the application wrote itself: the one remembered keeps its account and does not end the
     * reading, and the other is remembered -- by the replay already running too, if one is.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('whetherAFlushRanBefore')]
    public function testACollectionNothingLoadedIsReadPastALineAlreadyRemembered(string $before): void
    {
        $this->em->persist($crate = new Crate('C-8'));
        $crate->add(new CrateItem('SKU-A'));
        $this->em->flush();
        $this->em->getConnection()->insert('CrateItem', ['sku' => 'SKU-B', 'quantity' => 1, 'crate_id' => 'C-8', 'id' => 900801]);
        $this->em->clear();
        $this->unownedStatementsAreExpected = true;

        if ($before === 'none') {
            // A listener of its own, which has seen nothing yet.
            $this->attachListener(FailurePolicy::Throw);
        } else {
            if ($before === 'in a transaction') {
                $this->em->getConnection()->beginTransaction();
            }

            $this->em->persist(new Crate('C-0'));
            $this->em->flush();
        }

        $crate = $this->em->find(Crate::class, 'C-8');
        self::assertNotNull($crate);
        self::assertFalse($crate->items->isInitialized(), 'the premise: nothing loaded the lines');
        $this->gateway->documents = [];

        $crate->items = new ArrayCollection();
        $this->em->flush();

        if ($before === 'in a transaction') {
            $this->em->getConnection()->commit();
        }

        self::assertSame([['items' => ['old' => ['SKU-A', 'SKU-B'], 'new' => []]]], $this->theCollectionsChanges());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function whichSockRefuses(): iterable
    {
        yield 'the first' => [['refuses', 'red']];
        yield 'the last' => [['red', 'refuses']];
    }

    /**
     * An emptying whose representer fails for one of the lines: the emptying is not written with
     * a list nobody could show, and the failure goes through the policy -- whichever line it was.
     *
     * @param list<string> $colours
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('whichSockRefuses')]
    public function testAnEmptyingARepresenterFailsForIsNotWrittenAndIsSaid(array $colours): void
    {
        $this->em->persist($drawer = new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Drawer());

        foreach ($colours as $colour) {
            $drawer->add(new \Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Sock($colour));
        }

        $this->attachListener(FailurePolicy::Log);
        $this->em->flush();
        $this->gateway->documents = [];
        $this->logs = [];

        $drawer->socks = new ArrayCollection();
        $this->em->flush();

        self::assertSame([], array_values(array_filter($this->documents(), static fn (array $d): bool => isset($d['changes']['socks']))));
        self::assertCount(1, array_filter($this->logs, static fn (string $line): bool => str_starts_with($line, 'Audit record could not be written: RuntimeException')));
    }

    public function testAnEmptyingListsItsLinesByTheirKeysWhicheverWasRememberedFirst(): void
    {
        // The second line loaded, and so remembered, before the first: the emptying lists them
        // by their keys all the same.
        $this->em->persist($crate = new Crate('C-7'));
        $crate->add($first = new CrateItem('SKU-A'));
        $crate->add($second = new CrateItem('SKU-B'));
        $this->em->flush();
        [$firstId, $secondId] = [$first->id, $second->id];
        $this->em->clear();
        unset($crate, $first, $second);
        gc_collect_cycles();
        $this->em->persist(new Crate('C-0'));
        $this->em->flush();

        $this->em->find(CrateItem::class, $secondId);
        $this->em->find(CrateItem::class, $firstId);
        $crate = $this->em->find(Crate::class, 'C-7');
        self::assertNotNull($crate);
        $this->gateway->documents = [];

        $crate->items = new ArrayCollection();
        $this->em->flush();

        self::assertSame([['items' => ['old' => ['SKU-A', 'SKU-B'], 'new' => []]]], $this->theCollectionsChanges());
    }

    public function testAnEmptyingReadAfterAStatementOfTheOuterFlushNamesWhatThatStatementWrote(): void
    {
        // The outer flush renames X; X's postUpdate starts a nested flush that empties the
        // crate, whose other line nothing loaded. The rows read for the emptying are read after
        // the rename, and they are an account of what came after it only: the rename is still
        // 'SKU-X' -> 'SKU-X2', and the emptying names X as it was when it went.
        [$crate, $x] = $this->aCrateWithALoadedAndAnUnloadedLine();
        $xId = $x->id;

        $this->inThePostUpdateOf($x, function () use ($crate): void {
            $crate->items = new ArrayCollection();
            $this->em->flush();
        });

        $x->sku = 'SKU-X2';
        $this->em->flush();

        self::assertSame(
            [['items.'.$xId.'.sku' => ['old' => 'SKU-X', 'new' => 'SKU-X2']], ['items' => ['old' => ['SKU-X2', 'SKU-Y'], 'new' => []]]],
            $this->theCollectionsChanges(),
        );
    }

    public function testAnEmptyingTheApplicationRolledBackLeavesTheRenameAlone(): void
    {
        // The same, and what the nested flush did is rolled back by the application: it ran
        // the nested flush inside a transaction of its own and took that back. The rename
        // stays; the emptying never happened, and the rows read for it describe nothing that
        // is left. (A nested flush that dies instead closes the manager on its way out, and
        // what a clear does to the outer flush is step 4's.)
        $this->watchTheConnection(FailurePolicy::Log, savepoints: true);
        [$crate, $x] = $this->aCrateWithALoadedAndAnUnloadedLine();
        $xId = $x->id;

        $this->inThePostUpdateOf($x, function () use ($crate): void {
            $connection = $this->em->getConnection();
            $connection->beginTransaction();
            $crate->items = new ArrayCollection();
            $this->em->flush();
            $connection->rollBack();
        });

        $x->sku = 'SKU-X2';
        $this->em->flush();

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem WHERE crate_id = ?', ['C-1']), 'the premise: the emptying was rolled back');
        self::assertSame('SKU-X2', $this->em->getConnection()->fetchOne('SELECT sku FROM CrateItem WHERE id = ?', [$xId]), 'the premise: the rename stayed');
        self::assertSame([['items.'.$xId.'.sku' => ['old' => 'SKU-X', 'new' => 'SKU-X2']]], $this->theCollectionsChanges());
    }

    /**
     * What each document says about the crate's lines, and nothing else it carries.
     *
     * @return list<array<string, mixed>>
     */
    private function theCollectionsChanges(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $d): array => array_filter($d['changes'] ?? [], static fn (string $k): bool => str_starts_with($k, 'items'), \ARRAY_FILTER_USE_KEY),
            $this->documents(),
        )));
    }

    /**
     * @return array{0: Crate, 1: CrateItem}
     */
    private function aCrateWithALoadedAndAnUnloadedLine(): array
    {
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add(new CrateItem('SKU-X'));
        $crate->add(new CrateItem('SKU-Y'));
        $this->em->flush();
        $this->em->clear();

        $x = $this->em->getRepository(CrateItem::class)->findOneBy(['sku' => 'SKU-X']);
        $crate = $this->em->find(Crate::class, 'C-1');
        self::assertNotNull($x);
        self::assertNotNull($crate);
        self::assertInstanceOf(\Doctrine\ORM\PersistentCollection::class, $crate->items);
        self::assertFalse($crate->items->isInitialized(), 'the premise: the collection was never loaded');
        $this->gateway->documents = [];

        return [$crate, $x];
    }

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

    public function testALineAnInnerFlushInsertedAndAnOuterDeletionTookArrivedAndLeft(): void
    {
        // Written out from a generated sequence rather than composed, because every
        // arrangement composed in a readable order was covered by the old sweep instead:
        // the emptying that names this line was collected AFTER the sweep had dropped its
        // arrival. Under decision A the line's arrival and its departure are both facts,
        // each once, whatever order the flushes that wrote them came in.
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

        self::assertSame(1, \count(array_keys($said, 'C-1 items gained SKU-NEW', true)), 'it arrived, once');
        self::assertSame(1, \count(array_keys($said, 'C-1 items lost SKU-NEW', true)), 'and left, once');
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

    public function testARefusedInnerEmptyingDoesNotEraseTheOuterUpdate(): void
    {
        // The sweep that drops statements about rows an emptying takes reaches every flush
        // of the operation, so it is the one place a flush alters what ANOTHER flush
        // collected -- and a flush can die after doing it. Here a flush nested inside the
        // outer one empties the crate and is refused before any SQL of its own; the outer
        // flush goes on and writes its UPDATE. The inner flush had already swept the
        // outer's "1 -> 2" away, and discarding the inner flush's own buckets was never
        // going to bring back somebody else's: the row changed with no history of it.
        $this->attachListener(FailurePolicy::Log);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($line = new CrateItem('SKU-1', 1));
        $this->em->flush();

        $this->gateway->documents = [];

        $inner = new class($this->em, $crate, $line) {
            public bool $refused = false;

            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly Crate $crate,
                private readonly CrateItem $line,
            ) {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                if ($this->refused || $args->getObject() !== $this->line) {
                    return;
                }

                $this->crate->items = new ArrayCollection();

                $veto = new class {
                    public function onFlush(): void
                    {
                        throw new \DomainException('the inner flush is refused');
                    }
                };

                $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

                try {
                    $this->em->flush();
                } catch (\DomainException) {
                    $this->refused = true;
                } finally {
                    $this->em->getEventManager()->removeEventListener([Events::onFlush], $veto);
                }
            }
        };

        $line->quantity = 2;
        $this->em->getEventManager()->addEventListener([Events::preUpdate], $inner);

        try {
            $this->em->flush();
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::preUpdate], $inner);
        }

        self::assertTrue($inner->refused, 'the premise: the inner flush was refused before its SQL');
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT quantity FROM CrateItem'), 'and the outer one wrote its UPDATE');

        $changes = array_merge(...array_values(array_map(
            static fn (array $document): array => $document['changes'] ?? [],
            $this->documents(),
        )));

        self::assertSame(['old' => 1, 'new' => 2], $changes['items.'.$line->id.'.quantity'] ?? null, 'the change the row really took');
        self::assertNotContains('C-1 items lost SKU-1', $this->everyStatement(), 'and no departure: the DELETE never ran');

        // And the line is not left believed-gone: its next change is history like any other.
        $this->gateway->documents = [];
        $line->quantity = 3;
        $this->em->flush();

        $changes = array_merge(...array_values(array_map(
            static fn (array $document): array => $document['changes'] ?? [],
            $this->documents(),
        )));

        self::assertSame(['old' => 2, 'new' => 3], $changes['items.'.$line->id.'.quantity'] ?? null, 'the next change is recorded normally');
    }

    public function testAnInnerEmptyingAfterAnOuterRenameNamesTheValueAlreadyWritten(): void
    {
        // This listener's own change set outlives its statement, on purpose -- publishing
        // reads it after the commit. It was read here as if its presence meant "not yet
        // written". A flush nested inside the outer flush's postUpdate, after the rename had
        // reached the row, empties the collection: the row holds the new name and was named
        // by the old one. What says a statement is still to run is the flush number beside
        // the set, and that is cleared the moment the row matches the object.
        [$crate, , $first] = $this->twoCratesAndALine();

        $this->gateway->documents = [];

        $seen = [];
        $inner = $this->inThePostUpdateOf($first, function () use ($crate, $first, &$seen): void {
            $seen[] = (string) $this->em->getConnection()->fetchOne('SELECT sku FROM CrateItem WHERE id = ?', [$first->id]);
            $crate->items = new ArrayCollection();
            $this->em->flush();
        });

        $first->sku = 'SKU-RENAMED';
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate], $inner);

        self::assertSame(['SKU-RENAMED'], $seen, 'the premise: the rename was in the row when the nested flush began, and it began once');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem'), 'and the emptying took the rows');

        $emptied = [];

        foreach ($this->documents() as $document) {
            foreach ((array) ($document['changes']['items']['old'] ?? []) as $name) {
                $emptied[] = (string) $name;
            }
        }

        self::assertContains('SKU-RENAMED', $emptied, 'what the row held when it went');
        self::assertNotContains('SKU-1', $emptied, 'not what the UPDATE had already replaced');
    }

    public function testPuttingALineBackWhereItsRowIsStillRecordsItsQuantityChange(): void
    {
        // Putting a line back where its row already is moves nothing, and that is not a
        // move -- so it must not take the move's road, which records no fields of the line
        // because an owner a line ARRIVES at never held its old values. Here the owner held
        // them all along. The quantity changed in the same flush was written to the row and
        // to nobody's history.
        [$crate, $other, $first] = $this->twoCratesAndALine();

        $first->crate = $other;
        $this->flushRefused();

        self::assertSame(
            ['crate_id' => 'C-1', 'quantity' => 1],
            array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, (array) $this->em->getConnection()->fetchAssociative('SELECT crate_id, quantity FROM CrateItem WHERE id = ?', [$first->id])),
            'the premise: the refusal left the row where it was',
        );

        $this->gateway->documents = [];

        $first->crate = $crate;
        $first->quantity = 2;
        $this->em->flush();

        $changes = array_merge(...array_values(array_map(
            static fn (array $document): array => $document['changes'] ?? [],
            $this->documents(),
        )));

        self::assertSame(['old' => 1, 'new' => 2], $changes['items.'.$first->id.'.quantity'] ?? null, 'the change the row really took');
        self::assertArrayNotHasKey('items.'.$first->id, $changes, 'and no arrival or departure: nothing moved');
    }

    public function testARefusedNestedEmptyingAfterAMoveDoesNotEraseTheArrival(): void
    {
        // The membership half of what a dying nested flush puts back. The outer flush moves
        // a line into the crate; after the UPDATE, in the line's postUpdate, a flush nested
        // inside empties that crate -- the row it reads is already under it -- and is
        // refused before its SQL. It had swept the outer flush's arrival away, and the outer
        // flush committed the move with the crate never told.
        $this->attachListener(FailurePolicy::Log);

        $this->em->persist($crate = new Crate('C-1'));
        $this->em->persist($other = new Crate('C-2'));
        $other->add($line = new CrateItem('SKU-1'));
        $this->em->flush();

        $this->gateway->documents = [];

        $refused = false;
        $inner = $this->inThePostUpdateOf($line, function () use ($crate, &$refused): void {
            $crate->items = new ArrayCollection();

            $veto = new class {
                public function onFlush(): void
                {
                    throw new \DomainException('the inner flush is refused');
                }
            };

            $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);

            try {
                $this->em->flush();
            } catch (\DomainException) {
                $refused = true;
            } finally {
                $this->em->getEventManager()->removeEventListener([Events::onFlush], $veto);
            }
        });

        $line->crate = $crate;
        $this->em->flush();
        $this->em->getEventManager()->removeEventListener([Events::postUpdate], $inner);

        self::assertTrue($refused, 'the premise: the nested flush was refused');
        self::assertSame('C-1', (string) $this->em->getConnection()->fetchOne('SELECT crate_id FROM CrateItem'), 'and the move was written');

        $said = $this->everyStatement();

        self::assertContains('C-1 items gained SKU-1', $said, 'the arrival the row made');
        self::assertContains('C-2 items lost SKU-1', $said, 'and the departure');
        self::assertNotContains('C-1 items lost SKU-1', $said, 'and no emptying: its DELETE never ran');
    }

    /**
     * Runs something inside the postUpdate of one particular entity, once, AFTER this
     * listener's own postUpdate -- which is where a statement has already reached the row.
     */
    private function inThePostUpdateOf(object $entity, \Closure $what): object
    {
        $listener = new class($entity, $what) {
            private bool $ran = false;

            public function __construct(private readonly object $entity, private readonly \Closure $what)
            {
            }

            public function postUpdate(\Doctrine\ORM\Event\PostUpdateEventArgs $args): void
            {
                if ($this->ran || $args->getObject() !== $this->entity) {
                    return;
                }

                $this->ran = true;
                ($this->what)();
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postUpdate], $listener);

        return $listener;
    }

    public function testAnEmptyingAfterACommittedRenameNamesTheCommittedValue(): void
    {
        // The mirror of the refused rename, and the reason the two readers of a copy are
        // what they are. Here the rename WAS written; what was not cleared is the unit of
        // work's change set, because a postFlush listener threw. Read from there -- which
        // is what the copy used to do -- the deleted row was named by the name it had
        // before a rename that had already reached it. Whose row is going had stopped
        // trusting that leftover; the fields of the same element were still trusting it.
        [$crate, , $first] = $this->twoCratesAndALine();

        $first->sku = 'SKU-RENAMED';
        $this->flushWithTheirPostFlushThrowing();

        self::assertSame(
            'SKU-RENAMED',
            (string) $this->em->getConnection()->fetchOne('SELECT sku FROM CrateItem WHERE id = ?', [$first->id]),
            'the premise: the rename is in the column',
        );

        $this->gateway->documents = [];

        $crate->items = new ArrayCollection();
        $this->em->flush();

        // The emptying's own statement: the rename, published late in the same batch, is a
        // change inside the line and says SKU-1 truthfully, about a moment before.
        $emptied = [];

        foreach ($this->documents() as $document) {
            foreach ((array) ($document['changes']['items']['old'] ?? []) as $name) {
                $emptied[] = (string) $name;
            }
        }

        self::assertContains('SKU-RENAMED', $emptied, 'the name the row died with');
        self::assertNotContains('SKU-1', $emptied, 'not the one it had before a rename that was written');
    }

    public function testDeletingAfterACommittedDetachDoesNotRepeatTheDeparture(): void
    {
        // "Nobody" is an answer on every reader, and the fifth was the one left asking for
        // somebody. A line detached by a flush that WAS written -- its postFlush thrown --
        // and deleted afterwards: the column holds NULL, the original data holds NULL, and
        // the change set left behind still says the line came from the crate. Asked only
        // when both named somebody, the row was never consulted and the detach was
        // described a second time.
        [, , $first] = $this->twoCratesAndALine();

        $first->crate = null;
        $this->flushWithTheirPostFlushThrowing();

        self::assertNull(
            $this->em->getConnection()->fetchOne('SELECT crate_id FROM CrateItem WHERE id = ?', [$first->id]),
            'the premise: the detach is in the column',
        );

        $this->em->remove($first);
        $this->em->flush();

        self::assertCount(1, array_keys($this->everyStatement(), 'C-1 items lost SKU-1', true), 'the detach, once; the delete took a row no crate held');
    }

    public function testAnEmptyingPreservesANullStoredValueInItsRepresentation(): void
    {
        // A stored NULL is a value, and it was being taken for "nothing known". A column
        // that held nothing, given something in the same flush as its row was taken, was
        // shown with the value it never received -- the collection's DELETE runs first and
        // the UPDATE finds no row.
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist($bin = new Bin('B-1'));
        $bin->add($empty = new BinItem('SKU-1', null));
        $bin->add($full = new BinItem('SKU-2', 4));
        $this->em->flush();

        self::assertNull($this->em->getConnection()->fetchOne('SELECT count FROM BinItem WHERE id = ?', [$empty->id]), 'the premise: nothing is stored');

        $this->gateway->documents = [];

        $empty->count = 5;
        $full->count = 9;
        $bin->items = new ArrayCollection();
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM BinItem'), 'both rows went');

        $said = $this->everyStatement();

        self::assertContains('B-1 items lost SKU-1: unset', $said, 'the NULL the row held');
        self::assertNotContains('B-1 items lost SKU-1: 5', $said, 'not what it was about to be given');

        // The control, so that this cannot pass by showing nothing of the value at all.
        self::assertContains('B-1 items lost SKU-2: 4', $said, 'a stored value is shown as stored');
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
