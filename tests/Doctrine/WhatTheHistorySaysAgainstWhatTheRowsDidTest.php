<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\HideEveryLine;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Events;

/**
 * Sequences of ordinary operations, checked against what the database actually did.
 *
 * Nine of the findings of the last three rounds were sequences from one small vocabulary:
 * replace a collection, empty one, move a line, refuse a flush, swallow its publishing,
 * retry, nest one flush inside another. Every one was found by a person reading the code.
 * This is the attempt to stop needing that.
 *
 * **The database is the oracle, and Doctrine is not modelled.** Before and after every
 * commit the tables are read as they are, and the difference between the two readings is
 * what happened: a row appeared, a row went, a column moved, a line changed hands. The
 * history is then reduced to statements of the same kind, and the two sets must be equal.
 * Nothing here knows about flush numbers, stamps, claims, slices, or which of Doctrine's
 * roads carries out an emptying — knowing any of that would make this a second copy of
 * the thing it is checking.
 *
 * **What it deliberately does not do is compare against a second manager with no
 * listener.** That was the shape suggested for it, and it is the better oracle for "the
 * listener changed nothing" — but a second manager needs a second database, and two of
 * the databases this suite runs on in CI would give it the same one. That property has
 * its own tests (see TransactionSafetyTest); this one is about the history.
 *
 * **Both forms of a collection change count as the same statement.** "items.7 left" and
 * "items went from these to those" describe one fact, and which of them the bundle uses
 * is its own business; what must not happen is a fact described twice, or not at all, or
 * described when the rows say otherwise. Those three are the whole of this test, and they
 * are exactly the three shapes the last three rounds produced.
 *
 * **Where this search does not go, written out rather than discovered one at a time.**
 * Every line below is a shape it cannot produce, and every one of them is a place a defect
 * could be waiting. Four of the entries that used to be here came off by being widened
 * into the vocabulary after a person found a defect the search could not have reached; the
 * ones left are here for a stated reason, and the reason is what to argue with.
 *
 * The first three rest on one claim, and a claim of that shape has been wrong three times
 * in this candidate's review — so it is measured rather than asserted. The canaries
 * `testAReplacementTakesTheKeptElementsRowAndKeepsTheObject` and
 * `testAnUpdateInTheSameFlushAsAReplacementReachesNoRow` prove it about Doctrine, with no
 * part of this bundle in the way, and they go red if a release ever changes it.
 *
 *   1. **Replacing a collection with one that keeps an element it already had.** Doctrine
 *      deletes the old collection by the owner's key, which takes that element's row with
 *      the rest, and leaves the object in the identity map — so every step after it
 *      operates on something whose row is gone. That is between Doctrine and its own
 *      database. Pinned by hand instead, in
 *      `testAReplacementCannotKeepAnExistingOrphanAliveOnlyInTheAudit`.
 *      <br>Putting it back was tried rather than argued about: a thousand sequences with
 *      it then produced thirteen disagreements, twelve of them that one shape, over and
 *      over. Thirteen entries in the list below, twelve saying the same thing and all of
 *      them void the next time the vocabulary widens, is a worse record of this than one
 *      canary and one test. The thirteenth was real and is fixed —
 *      `testAChangeInsideALineWhoseRowHasNoOwnerBelongsToNoCrate` — and it needed none of
 *      this to be reached.
 *   2. **Touching a line while a REPLACEMENT of its collection waits for a flush**, for
 *      the reason {@see self::$aReplacementIsWaiting} gives. A `clear()` is no longer one
 *      of these: that flag used to cover both and was taking a shape the bundle really is
 *      answerable for out of the search. Two defects came out of that half alone.
 *   3. **A line Doctrine has and the database does not** — {@see self::stillARow()}. The
 *      same divergence as the first entry, reached from the other side.
 *      <br>Asked by the operations on a LINE, which choose a candidate, and now by the
 *      ones that empty the collection too — {@see self::holdsAPhantom()}. `clear()`
 *      chooses nothing: it sweeps up whatever the collection is holding, phantom
 *      included. A phantom arrives without being asked for once a flush can start inside
 *      another, and every one of the six sequences that hole produced was that shape.
 *   4. **A flush started from inside another one.** Rounds two to five of this candidate's
 *      review lived entirely there, and not one of their defects could be found here. This
 *      is the largest hole in the list and the next thing to close.
 *   5. **An owning many-to-many.** Emptying one deletes join rows and leaves the elements
 *      where they are, which is a different road through Doctrine and the only one that
 *      reads a join table. Covered by hand in
 *      `WhatAnEmptiedCollectionSaysAboutItsLinesTest`, not by this.
 *   6. **More than one owner of the same kind.** There is one crate whose lines are
 *      tracked, so nothing here can produce two owners disagreeing about one line.
 *
 * Seeds are fixed so a failure is reproducible, and `AUDIT_MODEL_SEEDS` runs more of them
 * when something is being hunted.
 */
final class WhatTheHistorySaysAgainstWhatTheRowsDidTest extends DoctrineTestCase
{
    /**
     * Whether a REPLACEMENT of the crate's collection is waiting for a flush.
     *
     * While one is, the lines of that collection are not touched. Replacing a collection
     * schedules a deletion Doctrine carries out with one raw statement, before it writes
     * any entity update — so an UPDATE for a line that statement is about to take affects
     * no rows, and Doctrine reports it as having happened all the same. That is the same
     * footgun as an entity left managed after its row is deleted, in one flush instead of
     * two: a divergence between Doctrine and its own database rather than between this
     * bundle and the rows.
     *
     * A `clear()` of the same collection is NOT one of these, and used to be treated as
     * one. Doctrine takes that road through orphan removals — an element scheduled for
     * deletion, one at a time — and it keeps up with what the application does to them
     * afterwards: putting a line back cancels its removal, moving it writes the new owner.
     * Doctrine and its database agree there, so this bundle may be judged on it, and the
     * flag was quietly taking the whole shape out of the search.
     */
    private bool $aReplacementIsWaiting = false;

    /** Where the next number comes from. */
    private int $from = 0;

    /**
     * Every line the world started with and every line a step has made since.
     *
     * A property rather than part of the world, because a step adds to it: the world used
     * to be handed over by value and held only its two original lines, so a line the
     * sequence created was never afterwards changed, moved or removed.
     *
     * @var list<CrateItem>
     */
    private array $lines = [];

    /**
     * What every line has been called, by its id.
     *
     * Kept as the snapshots go by, because the history names a line by its id and is read
     * after the sequence -- by which time the row that would have said its name may be
     * gone. Looking it up at the end turned a deleted line into a question mark, and two
     * statements about different lines into the same one.
     *
     * @var array<string, string>
     */
    private array $skus = [];

    /**
     * Sequences this does not yet describe correctly, and exactly how.
     *
     * Listed rather than silently skipped, and the list checks itself both ways. A listed
     * seed that starts passing fails this test until it is taken off, so the list cannot
     * quietly become a record of things that were fixed -- that is how five entries came
     * off in one go, none of them by the fix written for it. And a listed seed is excused
     * only for the ONE disagreement it is listed for: `says` is matched exactly, so a
     * defect replaced under the same number by a different one is not covered by its
     * entry.
     *
     * Anything here is an open finding, not accepted behaviour. The seeds are past the
     * default, so it is the long run -- the CI job that sets AUDIT_MODEL_SEEDS -- that
     * reaches them and does the checking. Widening the vocabulary makes every seed a
     * different sequence, so this list is emptied and rebuilt whenever it does.
     *
     * @var array<int, array{says: string, because: string}>
     */
    private const KNOWN = [
        // Empty. It has held entries three times in two days and been emptied three
        // times, and only once by the fix written for what was on it -- twice a fix for
        // something else took the whole list, which is the argument for keeping such
        // things in a list a test checks rather than in a note.
    ];

    /** The tables this reads, and the column that names each row in a statement. */
    private const TABLES = [
        'Article' => 'id',
        'Crate' => 'code',
        'CrateItem' => 'id',
    ];

    public function testEverySequenceIsDescribedByExactlyWhatTheRowsDid(): void
    {
        // Sixty here, and a thousand in a job of its own.
        //
        // Sixty was a number and not a measurement: it passed while twenty of the next
        // hundred and forty did not, and every one of those twenty was a real defect --
        // three roots between them, all in what the listener believed about a collection
        // it was about to see emptied. Five hundred found two more and a thousand one
        // more still. Widening what it may generate then found three more in the first two
        // thousand. So three thousand is what the project runs, and it runs it once, in a
        // CI job of its own: AUDIT_MODEL_SEEDS=3000, this test and nothing else.
        //
        // The reason is mutation testing. This test covers nearly every line of the
        // listener, so Infection runs it for nearly every mutant -- a thousand sequences
        // is a minute and three thousand is three, which is past the per-mutant timeout,
        // and a timeout is scored like a kill. The number that came back from putting a
        // thousand here was 90% with eight hundred and eighty-one mutants "too slow": a
        // score made of nothing.
        //
        // What each of those twenty defects left behind is a test of its own, written out
        // and watched failing, in WhatAnEmptiedCollectionSaysAboutItsLinesTest. This is
        // the search; those are the guards. Sixty is enough for the search to stay honest
        // in every ordinary run.
        $seeds = (int) ($_SERVER['AUDIT_MODEL_SEEDS'] ?? 60);
        $wrong = [];

        $mended = [];

        for ($seed = 1; $seed <= $seeds; ++$seed) {
            $said = $this->whatOneSequenceSaid($seed);
            $known = self::KNOWN[$seed]['says'] ?? null;

            // A listed seed is allowed to be wrong in the ONE way it is listed as being
            // wrong, and in no other. Listing a seed used to excuse any disagreement it
            // produced, so a defect could be fixed and replaced by a different one under
            // the same number without a word.
            if ($said !== null && $said['says'] !== $known) {
                $wrong[] = $said['story'];

                if (\count($wrong) >= 3) {
                    break; // three is enough to read; the rest would be the same story
                }
            }

            if ($said === null && $known !== null) {
                $mended[] = sprintf('%d (%s)', $seed, self::KNOWN[$seed]['because']);
            }
        }

        self::assertSame([], $wrong, sprintf("%d of %d sequences:\n\n%s", \count($wrong), $seeds, implode("\n\n", $wrong)));

        self::assertSame(
            [],
            $mended,
            'these sequences are listed as known to be wrong and are not any more; take them off the list: '.implode(', ', $mended),
        );
    }

    /**
     * The sequences that tell a rule apart, run on every build and not only in the long job.
     *
     * The search runs sixty sequences in the suite and three thousand in a job of its own,
     * and every defect it found beyond the sixty was found out there -- where mutation
     * testing never goes, because this test runs once per mutant and three thousand
     * sequences is past its timeout. A rule only a far seed could tell apart was guarded by
     * a job that runs once and seen by nothing that asks whether its guard works.
     *
     * **Measured, not remembered.** Each rule was taken out and three thousand sequences
     * were run against what was left; these are the seeds that failed. A seed's meaning is
     * the vocabulary it was drawn from, and the list was right to be distrusted: one seed
     * that had found a defect in the morning had stopped telling anything apart by the
     * afternoon, because a fix in between moved the road it was on. So it is re-measured
     * whenever the vocabulary or those rules change, and not kept on the strength of what a
     * seed once found.
     *
     * @var array<string, list<int>>
     */
    private const TELLS_APART = [
        // theseRowsAreGoing()'s set of rows an emptying took, asked whenever something new
        // is about to be written -- because a nested flush writes its part after the sweep.
        'the rows an emptying took stay taken for the rest of the operation' => [151, 378, 1087, 1416, 2943],

        // The emptying filtered against everything that vanished, and not only against what
        // the call that is running happened to find.
        'an emptying collected after the sweep leaves out what already vanished' => [949, 1516, 2557],

        // The question asked after a commit reads the ELEMENTS' table for a collection
        // mapped by them. A stale deletion handed to a flush with nothing else to do asks
        // it; with that shape taken out, the emptying was dropped and a row that went was
        // never mentioned.
        'the rows question reads an inverse collection too' => [2390],

        // Not a rule of the listener: the narrowing of this search's own generator, which
        // stops the operations that EMPTY a collection sweeping up a phantom. Without it,
        // these produce the divergence the search declares out of scope.
        'the emptying steps ask whether the crate holds a phantom' => [534, 1038, 1599, 2105, 2972],
    ];

    public function testTheSequencesThatTellARuleApartStillDescribeWhatTheRowsDid(): void
    {
        $wrong = [];

        foreach (self::TELLS_APART as $rule => $seeds) {
            foreach ($seeds as $seed) {
                $said = $this->whatOneSequenceSaid($seed);

                if ($said !== null) {
                    $wrong[] = $rule."\n".$said['story'];
                }
            }
        }

        self::assertSame([], $wrong, implode("\n\n", $wrong));
    }

    public function testTheOracleItselfCountsRepeatsRatherThanSets(): void
    {
        // The search is worth exactly what its oracle can see, and the commonest shape it
        // is for — one row described twice — disappears from any comparison that thinks in
        // sets. Two of this month's defects were that shape, and both of the places this
        // test pins have been written the set way at some point: `array_diff()` in the
        // whole-collection form, which takes out EVERY occurrence that appears in the
        // other list at all, and `array_unique()` as an obvious tidy-up of the answer.
        //
        // Without this, the next such tidy-up makes the search quietly blind and every
        // seed goes on passing.
        $twice = $this->statementsIn([
            ['objectType' => 'crate', 'objectId' => 'C-1', 'changes' => ['items.7' => ['old' => 'DUP', 'new' => null]]],
            ['objectType' => 'crate', 'objectId' => 'C-1', 'changes' => ['items.7' => ['old' => 'DUP', 'new' => null]]],
        ]);

        self::assertSame(['crate C-1 lost DUP', 'crate C-1 lost DUP'], $twice, 'two documents about one row are two statements');
        self::assertSame(['crate C-1 lost DUP'], self::missingFrom($twice, ['crate C-1 lost DUP']), 'and one of them is one too many');

        // The whole-collection form, where the count is the only thing that says a row
        // went: two lines called DUP became one, so exactly one of them left.
        self::assertSame(
            ['crate C-1 lost DUP'],
            $this->statementsIn([
                ['objectType' => 'crate', 'objectId' => 'C-1', 'changes' => ['items' => ['old' => ['DUP', 'DUP'], 'new' => ['DUP']]]],
            ]),
            'a namesake leaving a collection of namesakes',
        );

        self::assertSame([], self::missingFrom(['a', 'b'], ['b', 'a']), 'and order is not a difference');
    }

    /**
     * One sequence.
     *
     * Null when the history matched. Otherwise what is wrong in one line, for the listed
     * seeds to be matched on, and the whole story for a person to read.
     *
     * @return array{says: string, story: string}|null
     */
    private function whatOneSequenceSaid(int $seed): ?array
    {
        $this->setUp();
        $this->attachListener(FailurePolicy::Log);

        $this->from = $seed;
        $steps = $this->aSequence();
        $world = $this->aWorld($this->next(8));

        // Over the rows under test, and not over a table nothing here touches. This used
        // to enable a filter that hides Stops, in a world made of Articles, Crates and
        // CrateItems -- on for a quarter of every run and covering nothing. Hiding every
        // line is the soft-delete shape at its most extreme, and it is what makes "asked
        // underneath the application's filters" a claim this can break.
        if ($this->next(4) === 0) {
            $this->em->getConfiguration()->addFilter('hide_lines', HideEveryLine::class);
            $this->em->getFilters()->enable('hide_lines');
        }

        $this->gateway->documents = [];

        $trace = [];
        $rows = [];
        $this->skus = [];
        $this->aReplacementIsWaiting = false;

        foreach ($steps as $step) {
            $trace[] = $step;

            // Around each flush of its own, not around the sequence. A line that leaves a
            // crate and comes back nets to nothing over a sequence and is two things that
            // happened, and the history is right to say both.
            $before = $this->snapshot();
            $this->apply($step, $world);
            $rows = array_merge($rows, $this->statementsBetween($before, $this->snapshot()));
        }

        sort($rows);
        $history = $this->statementsIn($this->documents());

        if ($rows === $history) {
            return null;
        }

        $missing = self::missingFrom($rows, $history);
        $invented = self::missingFrom($history, $rows);

        return [
            // What is wrong, in one line and nothing else: what the listed seeds are
            // matched on, so that a seed failing differently is not covered by its entry.
            'says' => sprintf('missing %s, invented %s', json_encode($missing), json_encode($invented)),
            'story' => sprintf(
                "seed %d\n  steps:    %s\n  the rows: %s\n  the audit: %s\n  missing:  %s\n  invented: %s",
                $seed,
                implode(', ', $trace),
                json_encode($rows),
                json_encode($history),
                json_encode($missing),
                json_encode($invented),
            ),
        ];
    }

    /**
     * The next number below a bound, from the seed and nothing else.
     *
     * Written out rather than taken from \Random\Randomizer, which arrived in PHP 8.2 and
     * this bundle supports 8.1 -- a mistake made here and caught by CI rather than by the
     * machine it was written on. It also makes a seed mean the same sequence on every
     * version, which a seeded engine does not promise across majors and which is the whole
     * point of printing the seed when one fails.
     */
    private function next(int $below): int
    {
        // An ordinary linear congruential step, the constants Numerical Recipes'.
        $this->from = ($this->from * 1664525 + 1013904223) % 2147483648;

        return intdiv($this->from, 65536) % $below;
    }

    /**
     * @return list<string>
     */
    private function aSequence(): array
    {
        $vocabulary = [
            'edit the article',
            'change a line',
            'move a line',
            'move a line back',
            'add a line',
            'add a namesake',
            'remove a line',
            'empty the crate',
            'replace the crate',
            'replace the crate with a new line',
        ];

        $steps = [];

        for ($i = 0, $n = 1 + $this->next(4); $i < $n; ++$i) {
            $steps[] = $vocabulary[$this->next(\count($vocabulary))];

            // Sometimes nothing, so that two or three things reach one flush together.
            // Every action used to be followed at once by an attempt to flush, and the
            // only way several of them ever met in one operation was a refusal in between
            // -- which is a road of its own and not the ordinary one. An application does
            // several things and then saves.
            if ($this->next(3) === 0) {
                continue;
            }

            // How the flush that carries it out ends.
            $steps[] = match ($this->next(8)) {
                0 => 'flush, refused',
                1 => 'flush, publishing swallowed',
                2 => 'flush, with one nested inside',
                default => 'flush',
            };
        }

        // Whatever a refusal left behind is carried out by one last ordinary flush, so the
        // sequence always ends somewhere the rows and the history can both be read.
        $steps[] = 'flush';

        return $steps;
    }

    /**
     * The world the sequence runs against, in one of eight shapes.
     *
     * Three bits, each adding a line the search could not reach before: one with a name
     * another line already has, one no crate owns, and one in the second crate. Each was
     * put here after a defect of its shape was found by a person reading the code, which
     * is what a search not finding it means.
     *
     * @return array{article: Article, crate: Crate, other: Crate, lines: list<CrateItem>}
     */
    private function aWorld(int $shape): array
    {
        $this->em->persist($article = new Article('One'));
        $this->em->persist($crate = new Crate('C-1'));
        $this->em->persist($other = new Crate('C-2'));

        $crate->add($first = new CrateItem('SKU-1'));
        $crate->add($second = new CrateItem('SKU-2'));

        $lines = [$first, $second];

        // A namesake. Two lines of one crate may perfectly well carry the same name, and
        // anything that tells elements apart by what they are SHOWN as cannot tell these
        // two apart. A defect of exactly that shape was found by a person and could not
        // have been found here, because every line this generates had a name of its own.
        if (($shape & 1) !== 0) {
            $crate->add($lines[] = new CrateItem('SKU-1'));
        }

        // A line no crate owns. Its column is null, and null is an ANSWER -- "this row
        // belonged to nobody" -- rather than the absence of one. A defect of that shape
        // was found by a person too, for the same reason: the world had no such line.
        if (($shape & 2) !== 0) {
            $this->em->persist($lines[] = new CrateItem('SKU-LOOSE'));
        }

        if (($shape & 4) !== 0) {
            $other->add($lines[] = new CrateItem('SKU-3'));
        }

        $this->em->flush();

        $this->lines = $lines;

        return ['article' => $article, 'crate' => $crate, 'other' => $other, 'lines' => $lines];
    }

    /**
     * @param array{article: Article, crate: Crate, other: Crate, lines: list<CrateItem>} $world
     */
    private function apply(string $step, array $world): void
    {
        $crate = $world['crate'];

        // Only lines the manager still has and is not already deleting. An operation on an
        // entity the application has thrown away is not something an application does, and
        // a sequence generator that does it is testing Doctrine's tolerance rather than
        // this bundle's history.
        //
        // Every line the sequence has made, not the two the world started with: a line
        // added by one step was never afterwards edited, moved or removed, so half the
        // vocabulary could only ever be applied to the same two rows.
        $alive = array_values(array_filter(
            $this->lines,
            fn (CrateItem $line): bool => $this->em->contains($line)
                && !$this->em->getUnitOfWork()->isScheduledForDelete($line)
                && $this->stillARow($line),
        ));

        // In this crate, and blocked only while a replacement of THIS collection waits.
        $lines = $this->aReplacementIsWaiting ? [] : array_values(array_filter(
            $alive,
            static fn (CrateItem $line): bool => $line->crate === $crate,
        ));

        // Somewhere else, or nowhere: the other side of a move, which the search could
        // not reach at all because a line only ever travelled away from the first crate.
        $elsewhere = array_values(array_filter(
            $alive,
            static fn (CrateItem $line): bool => $line->crate !== $crate,
        ));

        match ($step) {
            'edit the article' => $world['article']->title = 'title '.\count($this->documents()),
            'change a line' => $lines === [] ? null : $lines[0]->quantity = ($lines[0]->quantity ?? 0) + 1,
            'move a line' => $lines === [] ? null : $lines[0]->crate = $world['other'],
            'move a line back' => $elsewhere === [] || $this->aReplacementIsWaiting ? null : $elsewhere[0]->crate = $crate,
            'add a line' => $crate->add($this->lines[] = new CrateItem('SKU-'.spl_object_id($crate).'-'.\count($this->queries))),
            'add a namesake' => $lines === [] ? null : $crate->add($this->lines[] = new CrateItem($lines[0]->sku)),
            'remove a line' => $lines === [] ? null : $this->em->remove($lines[0]),
            'empty the crate' => $this->holdsAPhantom($crate) ? null : $this->empty($crate),
            'replace the crate' => $this->holdsAPhantom($crate) ? null : $this->replace($crate, []),
            'replace the crate keeping one' => $this->holdsAPhantom($crate) ? null : $this->replace($crate, $lines === [] ? [] : [$lines[0]]),
            'replace the crate with a new line' => $this->holdsAPhantom($crate) ? null : $this->replaceWithANewLine($crate),
            'flush' => $this->flush(),
            'flush, refused' => $this->flushRefused(),
            'flush, publishing swallowed' => $this->flushWithTheirPostFlushThrowing(),
            'flush, with one nested inside' => $this->flushWithOneNestedInside($world['article']),
            default => throw new \LogicException('no such step: '.$step),
        };
    }

    /**
     * Whether the crate is holding a line Doctrine has and the database does not.
     *
     * The third narrowing, applied to the one road that went round it. Every operation on
     * a LINE already asks {@see self::stillARow()} before choosing a candidate, because
     * what a history says about an object whose row is gone is between Doctrine and its
     * own database. Emptying the collection chooses no candidate: `clear()` sweeps up
     * whatever the collection is holding, phantom included, and the flush after that
     * records the departure of a row that had already gone.
     *
     * A phantom arrives here without being asked for -- a flush nested inside another
     * carries out the outer one's collection deletion a second time, taking the row of a
     * line that flush had just inserted and leaving the object managed. That is the same
     * divergence `testAReplacementTakesTheKeptElementsRowAndKeepsTheObject` pins about
     * Doctrine, reached by a different road, and the six sequences it used to produce were
     * every one of them that shape.
     */
    private function holdsAPhantom(Crate $crate): bool
    {
        foreach ($crate->items as $line) {
            if ($line instanceof CrateItem && $line->id !== null && !$this->stillARow($line)) {
                return true;
            }
        }

        return false;
    }

    private function empty(Crate $crate): void
    {
        $crate->items->clear();
        $this->aReplacementIsWaiting = true;
    }

    /**
     * @param list<CrateItem> $keeping
     */
    private function replace(Crate $crate, array $keeping): void
    {
        $crate->items = new ArrayCollection($keeping);
        $this->aReplacementIsWaiting = true;
    }

    private function replaceWithANewLine(Crate $crate): void
    {
        $this->replace($crate, []);
        $crate->add(new CrateItem('SKU-new-'.\count($this->queries)));
    }

    private function flush(): void
    {
        $this->aReplacementIsWaiting = false;

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // An operation the application could not complete is not this test's subject;
            // what matters is that the history matches whatever the rows ended up as.
        }
    }

    private function flushRefused(): void
    {
        // The emptying stays waiting: a refusal leaves Doctrine's schedule exactly as it
        // was, so the next flush is the one that will carry it out.

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

    /**
     * A flush with another one started from inside it, which is where four rounds lived.
     *
     * The application writes something in response to a change — the ordinary shape of it
     * is a preUpdate or postUpdate listener that touches a second entity and saves. What
     * makes it worth generating is that the inner flush shares the outer one's unit of
     * work: it is handed rows the outer flush has not written yet, its own scheduling is
     * folded into the outer one's, and everything this listener keeps per flush has two
     * flushes to keep apart. Rounds two to five of this candidate's review were nothing
     * else, and not one of their defects could have been found here until now.
     *
     * Once per flush, from the first preUpdate it sees: recursing would be testing
     * Doctrine's patience rather than this bundle's history.
     */
    private function flushWithOneNestedInside(Article $article): void
    {
        $this->aReplacementIsWaiting = false;

        $inner = new class($this->em, $article) {
            private bool $ran = false;

            public function __construct(
                private readonly \Doctrine\ORM\EntityManagerInterface $em,
                private readonly Article $article,
            ) {
            }

            public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;
                $this->article->title = 'from inside';
                $this->em->flush();
            }
        };

        $this->em->getEventManager()->addEventListener([Events::preUpdate], $inner);

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Whatever the application could not complete is not this test's subject.
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::preUpdate], $inner);
        }
    }

    private function flushWithTheirPostFlushThrowing(): void
    {
        $this->aReplacementIsWaiting = false;

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

        $this->em->getEventManager()->addEventListener([Events::postFlush], $breaker);

        foreach ($ours as $listener) {
            $this->em->getEventManager()->addEventListener([Events::postFlush], $listener);
        }

        try {
            $this->em->flush();
        } catch (\DomainException) {
            // the application copes
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::postFlush], $breaker);
        }
    }

    /**
     * Whether the database still has this line.
     *
     * Asked because Doctrine does not always know. Replacing a collection that has
     * orphanRemoval deletes its rows with one statement and leaves the entities in the
     * identity map, so an application that goes on using one is holding an object whose
     * row is gone: the UPDATE Doctrine then writes for it changes nothing, and the history
     * describes a move the database never made. That is a real divergence and it is not
     * this bundle's to fix -- what the listener records is what Doctrine told it happened
     * -- so the sequences here stay on the side of it an application is on.
     */
    private function stillARow(CrateItem $line): bool
    {
        return $this->em->getConnection()->fetchOne('SELECT 1 FROM CrateItem WHERE id = ?', [$line->id]) !== false;
    }

    /**
     * Every row of every table this test knows about, by table and key.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $taken = [];

        foreach (self::TABLES as $table => $key) {
            foreach ($this->em->getConnection()->fetchAllAssociative('SELECT * FROM '.$table) as $row) {
                $taken[$table][(string) $row[$key]] = $row;

                if ($table === 'CrateItem') {
                    $this->skus[(string) $row['id']] = (string) $row['sku'];
                }
            }
        }

        return $taken;
    }

    /**
     * What the rows did, as statements.
     *
     * @param array<string, array<string, array<string, mixed>>> $before
     * @param array<string, array<string, array<string, mixed>>> $after
     *
     * @return list<string>
     */
    private function statementsBetween(array $before, array $after): array
    {
        $said = [];

        foreach (($before['Article'] ?? []) + ($after['Article'] ?? []) as $id => $ignored) {
            $was = $before['Article'][$id]['title'] ?? null;
            $is = $after['Article'][$id]['title'] ?? null;

            if ($was !== $is) {
                $said[] = sprintf('article %s title %s -> %s', $id, json_encode($was), json_encode($is));
            }
        }

        // A line belongs to a crate, so what a line did is said about its crate: which is
        // also the only place the history says it.
        foreach (($before['CrateItem'] ?? []) + ($after['CrateItem'] ?? []) as $id => $ignored) {
            $was = $before['CrateItem'][$id] ?? null;
            $is = $after['CrateItem'][$id] ?? null;
            $sku = $was['sku'] ?? $is['sku'] ?? '?';

            if (($was['crate_id'] ?? null) !== ($is['crate_id'] ?? null)) {
                if (($was['crate_id'] ?? null) !== null) {
                    $said[] = sprintf('crate %s lost %s', $was['crate_id'], $sku);
                }

                if (($is['crate_id'] ?? null) !== null) {
                    $said[] = sprintf('crate %s gained %s', $is['crate_id'], $sku);
                }
            } elseif ($was !== null && $is !== null && $was['quantity'] !== $is['quantity']) {
                $said[] = sprintf('crate %s line %s quantity %s -> %s', $is['crate_id'], $sku, json_encode($was['quantity']), json_encode($is['quantity']));
            }
        }

        return $said;
    }

    /**
     * What the history says, as statements of the same kind.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return list<string>
     */
    private function statementsIn(array $documents): array
    {
        $said = [];

        foreach ($documents as $document) {
            $type = $document['objectType'];
            $id = (string) $document['objectId'];

            foreach ($document['changes'] as $field => $change) {
                $old = $change['old'] ?? null;
                $new = $change['new'] ?? null;

                if ($old === $new) {
                    continue; // context beside the change, not a change
                }

                if ($type === 'article' && $field === 'title') {
                    $said[] = sprintf('article %s title %s -> %s', $id, json_encode($old), json_encode($new));

                    continue;
                }

                if ($type !== 'crate') {
                    continue;
                }

                // The whole-collection form, which says the same thing as a handful of the
                // other one: these left, those arrived.
                if ($field === 'items') {
                    // Counting repeats, which array_diff() does not: it takes out EVERY
                    // occurrence that appears in the other list at all, so a collection
                    // going from two lines called DUP to one produced no statement while
                    // the rows had plainly lost one. The oracle would have been blind to
                    // the namesake defects in exactly the place they live.
                    foreach (self::missingFrom((array) $old, (array) $new) as $sku) {
                        $said[] = sprintf('crate %s lost %s', $id, $sku);
                    }

                    foreach (self::missingFrom((array) $new, (array) $old) as $sku) {
                        $said[] = sprintf('crate %s gained %s', $id, $sku);
                    }

                    continue;
                }

                $parts = explode('.', (string) $field);

                if ($parts[0] !== 'items') {
                    continue;
                }

                if (\count($parts) === 2) {
                    $said[] = $new === null
                        ? sprintf('crate %s lost %s', $id, $old)
                        : sprintf('crate %s gained %s', $id, $new);

                    continue;
                }

                if (($parts[2] ?? null) === 'quantity') {
                    $said[] = sprintf('crate %s line %s quantity %s -> %s', $id, $this->skuOf($parts[1]), json_encode($old), json_encode($new));
                }
            }
        }

        sort($said);

        return $said;
    }

    /**
     * What the second list does not have enough of, counting repeats.
     *
     * array_diff() would not: a statement the history makes twice and the rows make once
     * is the shape of two of this month's defects, and it disappears from a difference
     * that thinks in sets.
     *
     * @param list<string> $these
     * @param list<string> $from
     *
     * @return list<string>
     */
    private static function missingFrom(array $these, array $from): array
    {
        $left = array_count_values($from);
        $short = [];

        foreach (array_count_values($these) as $said => $times) {
            for ($i = ($left[$said] ?? 0); $i < $times; ++$i) {
                $short[] = (string) $said;
            }
        }

        return $short;
    }

    private function skuOf(string $line): string
    {
        return $this->skus[$line] ?? '?';
    }
}
