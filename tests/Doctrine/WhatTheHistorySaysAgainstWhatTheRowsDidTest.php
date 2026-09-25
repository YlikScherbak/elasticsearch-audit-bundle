<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\HideEveryLine;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\Events;
use Psr\Log\AbstractLogger;

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
     * How many times each word of the vocabulary did what it is named for, across the
     * sequences run so far -- not how often it was drawn. A word drawn when there is nothing
     * for it to act on does nothing, and a change to the generator can make a word do
     * nothing every time: three thousand sequences would then stay green about a world
     * without that shape in it. So every word has to have acted at least once
     * ({@see self::testEverySequenceIsDescribedByExactlyWhatTheRowsDid()}), which is the
     * generator's side of what counting doubt is on the listener's. Across sequences, so not
     * reset by setUp().
     *
     * @var array<string, int>
     */
    private array $acted = [];

    /**
     * How many lines this sequence has made, which is what a new line is named by.
     *
     * They were named by the crate's object id and by how many statements had run, and
     * neither is a function of the seed: an object id depends on everything the process
     * allocated before, so a seed drew the same steps and wrote its disagreement under a
     * different name depending on which tests ran first -- and an entry of KNOWN, matched
     * exactly, stopped matching. A count of statements moves whenever the listener asks
     * the database one question more or less, which is exactly what the next phase changes.
     */
    private int $made = 0;

    /**
     * Readings of the tables taken INSIDE a step, at the edges of a nested flush.
     *
     * The rows are read before and after every step, and the difference is what happened.
     * A flush nested inside another writes in the middle of a step, so two UPDATEs of one
     * column -- the outer flush's and then the nested one's -- netted to one change between
     * the readings, while the history rightly said both. The nested helpers read the tables
     * on either side of the inner flush, and the step is taken apart at those points.
     *
     * And before every statement that writes (middlewaresOfTheTest()), which is the reading
     * after the one before it. The history keeps every statement that reached a row -- a
     * line changed and then removed by the same flush is two facts -- and readings only at
     * the edges of flushes netted such a pair to its end. Read on the connection that runs
     * them, so that what a transaction has not committed is seen; never from the bundle's
     * own log, which is what this is here to check.
     *
     * @var list<array<string, array<string, array<string, mixed>>>>
     */
    private array $checkpoints = [];

    /**
     * How many readings there were when each transaction or savepoint still open began: a
     * rollback takes the readings of what it undid with it, since none of that happened.
     *
     * @var list<array{0: string|null, 1: int}>
     */
    private array $open = [];

    private bool $reading = false;

    /**
     * @return list<\Doctrine\DBAL\Driver\Middleware>
     */
    protected function middlewaresOfTheTest(): array
    {
        return [new LoggingMiddleware(new class(\Closure::fromCallable([$this, 'beforeAStatement'])) extends AbstractLogger {
            public function __construct(private readonly \Closure $before)
            {
            }

            /**
             * @param mixed               $level
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                ($this->before)((string) $message, $context['sql'] ?? null);
            }
        })];
    }

    /**
     * What DBAL's logging middleware says, just before the driver is asked: a transaction
     * begun, committed or rolled back, or a statement about to run.
     */
    private function beforeAStatement(string $message, mixed $sql): void
    {
        if ($this->reading) {
            return; // the reading's own SELECTs
        }

        match ($message) {
            'Beginning transaction' => $this->open[] = [null, \count($this->checkpoints)],
            'Committing transaction' => array_pop($this->open),
            'Rolling back transaction' => $this->undoTo($this->open[0][1] ?? \count($this->checkpoints), all: true),
            default => \is_string($sql) ? $this->aStatement($sql) : null,
        };
    }

    private function aStatement(string $sql): void
    {
        $words = preg_split('~\s+~', strtoupper(trim($sql))) ?: [];

        match (true) {
            $words[0] === 'SAVEPOINT' => $this->open[] = [$words[1] ?? '', \count($this->checkpoints)],
            $words[0] === 'RELEASE' => $this->forgetSavepoint(end($words) ?: ''),
            $words[0] === 'ROLLBACK' && ($words[1] ?? '') === 'TO' => $this->rollBackToSavepoint(end($words) ?: ''),
            \in_array($words[0], ['INSERT', 'UPDATE', 'DELETE'], true) => $this->read(),
            default => null,
        };
    }

    private function read(): void
    {
        $this->reading = true;

        try {
            $this->checkpoints[] = $this->snapshot();
        } finally {
            $this->reading = false;
        }
    }

    private function forgetSavepoint(string $name): void
    {
        for ($i = \count($this->open) - 1; $i >= 0; --$i) {
            if ($this->open[$i][0] === $name) {
                array_splice($this->open, $i);

                return;
            }
        }
    }

    private function rollBackToSavepoint(string $name): void
    {
        for ($i = \count($this->open) - 1; $i >= 0; --$i) {
            if ($this->open[$i][0] === $name) {
                $this->checkpoints = \array_slice($this->checkpoints, 0, $this->open[$i][1]);
                array_splice($this->open, $i + 1); // the savepoint itself stays open

                return;
            }
        }
    }

    private function undoTo(int $readings, bool $all): void
    {
        $this->checkpoints = \array_slice($this->checkpoints, 0, $readings);

        if ($all) {
            $this->open = [];
        }
    }

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
     * quietly become a record of things that were fixed. And a listed seed is excused only
     * for the ONE disagreement it is listed for: `says` is matched exactly, so a defect
     * replaced under the same number by a different one is not covered by its entry.
     *
     * Empty, and the emptiness is the claim: no disagreement is known, and one that appears
     * fails this until it is either mended or written here with its reason. It held 225 of
     * three thousand at its longest -- all of them the listener's rule that a line which came
     * and went across the flushes of one operation had no history, and a sweep for rows being
     * taken that reached changes another flush had written. Both went with the move to
     * reading the connection's log (steps 2 and 3); the list was measured empty on the corpus
     * it was made from, with no doubt logged and no oracle change (OracleChanges.md) to
     * account for it. Widening the vocabulary makes every seed a different sequence, so an
     * entry made after that is about the new sequences.
     *
     * Step 4 widened the vocabulary (2026-09-25) and drew six, all one root: the article's old
     * side after a savepoint of the application's took its UPDATE back. They are the record's
     * value, not its fate -- the record of the taken-back UPDATE is dropped as it should be --
     * and step 5, which reads an entity's fields from the log, is where they go.
     *
     * @var array<int, array{says: string, because: string}>
     */
    private const KNOWN = [
        594 => ['says' => 'missing ["article 1 title \\"One\\" -> \\"title 1\\""], invented ["article 1 title \\"title 0\\" -> \\"title 1\\""]', 'because' => 'step 5: an entity\'s fields are read from Doctrine\'s change set, and after the application rolled back an UPDATE of the article to a savepoint of its own Doctrine still believes the row took it -- the next change\'s old side is the value the row never held'],
        732 => ['says' => 'missing ["article 1 title \\"One\\" -> \\"from after\\""], invented ["article 1 title \\"title 0\\" -> \\"from after\\""]', 'because' => 'step 5: an entity\'s fields are read from Doctrine\'s change set, and after the application rolled back an UPDATE of the article to a savepoint of its own Doctrine still believes the row took it -- the next change\'s old side is the value the row never held'],
        841 => ['says' => 'missing ["article 1 title \\"One\\" -> \\"title 1\\""], invented ["article 1 title \\"title 0\\" -> \\"title 1\\""]', 'because' => 'step 5: an entity\'s fields are read from Doctrine\'s change set, and after the application rolled back an UPDATE of the article to a savepoint of its own Doctrine still believes the row took it -- the next change\'s old side is the value the row never held'],
        1030 => ['says' => 'missing ["article 1 title \\"One\\" -> \\"title 1\\""], invented ["article 1 title \\"title 0\\" -> \\"title 1\\""]', 'because' => 'step 5: an entity\'s fields are read from Doctrine\'s change set, and after the application rolled back an UPDATE of the article to a savepoint of its own Doctrine still believes the row took it -- the next change\'s old side is the value the row never held'],
        2480 => ['says' => 'missing ["article 1 title \\"One\\" -> \\"title 1\\""], invented ["article 1 title \\"title 0\\" -> \\"title 1\\""]', 'because' => 'step 5: an entity\'s fields are read from Doctrine\'s change set, and after the application rolled back an UPDATE of the article to a savepoint of its own Doctrine still believes the row took it -- the next change\'s old side is the value the row never held'],
        2703 => ['says' => 'missing ["article 1 title \\"One\\" -> \\"title 2\\""], invented ["article 1 title \\"title 0\\" -> \\"title 2\\""]', 'because' => 'step 5: an entity\'s fields are read from Doctrine\'s change set, and after the application rolled back an UPDATE of the article to a savepoint of its own Doctrine still believes the row took it -- the next change\'s old side is the value the row never held'],
    ];

    /**
     * What a sequence does between its flushes.
     *
     * Widened in step 4 (2026-09-25) by a clear of the manager between two operations; the
     * vocabulary before it is {@see self::VOCABULARY_3_3}, kept so that the corpus it drew --
     * three thousand sequences, green on DBAL 3 and 4 -- can still be run as it was:
     * AUDIT_MODEL_VOCABULARY=3.3.
     */
    private const VOCABULARY = [
        ...self::VOCABULARY_3_3,
        'clear the manager',
    ];

    /** How a flush of a sequence may end, besides the ordinary way. */
    private const ENDINGS = [
        ...self::ENDINGS_3_3,
        // Step 4: a clear inside a flush, from a listener ahead of this one -- the shape the
        // listener used to answer by forgetting the flush -- and the one that loads a line
        // afresh after it and removes it in the same flush.
        'flush, and after the statement a listener ahead of this one clears the manager',
        'flush, and after the statement a listener clears the manager, loads a line and removes it',
        // A savepoint of the application's around one statement of the flush, rolled back by a
        // listener behind this one or ahead of it: the execution taken back after its record was
        // taken, or before. The class a colleague's counterexample found and this did not.
        'flush, and a savepoint of the application\'s around a statement is rolled back after this listener',
        'flush, and a savepoint of the application\'s around a statement is rolled back before this listener',
    ];

    /** The endings that act on the UPDATE of a line in their flush, and need one there. */
    private const ENDINGS_ON_A_LINES_UPDATE = [
        'flush, and after the statement a listener removes the line',
        'flush, and after the statement a nested one removes the line',
        'flush, and after the statement a listener ahead of this one clears the manager',
        'flush, and after the statement a listener clears the manager, loads a line and removes it',
        'flush, and a savepoint of the application\'s around a statement is rolled back after this listener',
        'flush, and a savepoint of the application\'s around a statement is rolled back before this listener',
    ];

    /** @see self::VOCABULARY */
    private const VOCABULARY_3_3 = [
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

    /** @see self::VOCABULARY */
    private const ENDINGS_3_3 = [
        'flush, refused',
        'flush, publishing swallowed',
        'flush, with one nested inside',
        'flush, and after the statement a nested one edits the article',
        'flush, and after the statement a nested one empties the crate',
        'flush, and after the statement a nested one empties the crate and is refused',
        'flush, and after the statement a listener removes the line',
        'flush, and after the statement a nested one removes the line',
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
            $known = self::known($seed);

            // Excused for the ONE way it is listed as being wrong, and in no other.
            if ($said !== null && $said['says'] !== ($known['says'] ?? null)) {
                $wrong[] = $said['story'];

                if (\count($wrong) >= 3) {
                    break; // three is enough to read; the rest would be the same story
                }
            }

            if ($said === null && $known !== null) {
                $mended[] = sprintf('%d (%s)', $seed, $known['because']);
            }
        }

        self::assertSame([], $wrong, sprintf("%d of %d sequences:\n\n%s", \count($wrong), $seeds, implode("\n\n", $wrong)));

        // Two levels, because they fail for different reasons. At the default sixty a word
        // that did nothing is most likely a word the seeds stopped drawing -- the next word
        // added redraws every one of them -- and the answer is to look at the draw. In the
        // long run a word that acts far less than it did is a word that died: at 3000 the
        // rarest acted 169 times when step 4 widened the vocabulary, and a thirtieth of the
        // sequences run is what it must not fall under.
        $words = [...self::theVocabulary()[0], ...self::theVocabulary()[1], 'flush'];

        if ($seeds >= 60) {
            self::assertSame([], array_values(array_filter(
                $words,
                fn (string $word): bool => ($this->acted[$word] ?? 0) === 0,
            )), sprintf('these words of the vocabulary did nothing in %d sequences -- most likely the seeds stopped drawing them; look at the draw before the listener', $seeds));
        }

        if ($seeds >= 3000) {
            $floor = intdiv($seeds, 30);

            self::assertSame([], array_values(array_filter(
                $words,
                fn (string $word): bool => ($this->acted[$word] ?? 0) < $floor,
            )), sprintf('these words acted fewer than %d times in %d sequences: a word of the search has died', $floor, $seeds));
        }

        self::assertSame(
            [],
            $mended,
            'these sequences are listed as known to be wrong and are not any more; take them off the list: '.implode(', ', $mended),
        );

        // And none of them left the listener unable to follow what the connection ran: an
        // empty list of disagreements bought with doubt would say nothing, since doubt is
        // what the history leaves out. The listener says so in its log, and it did not.
        self::assertSame([], array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'may be missing what they did'))), 'the log could not be followed');
    }

    /**
     * The sequences that tell a rule apart, run on every build and not only in the long job.
     *
     * The search runs sixty sequences in the suite and three thousand in a job of its own,
     * and every defect it found beyond the sixty was found out there -- where mutation
     * testing never goes, because this test runs once per mutant and three thousand
     * sequences is past its timeout.
     *
     * **Kept as the steps, not as the seeds.** A seed is a function of the generator, and
     * the generator grows every round: the next word added to the vocabulary makes every
     * seed draw a different sequence, and a list of seeds would go on passing while
     * describing fourteen other things. The seed each was drawn by is kept beside it, as
     * where it was found and nothing more.
     *
     * **Measured, not remembered.** Each rule was taken out and three thousand sequences
     * run against what was left; these are the ones that failed. One seed that found a
     * defect in the morning had stopped telling anything apart by the afternoon, because a
     * fix in between moved the road it was on -- so the list is re-measured when a rule
     * changes, and a sequence that stops telling its rule apart is taken off rather than
     * kept for what it once found.
     *
     * **An excuse is one disagreement, named exactly.** A sequence may carry, as a fourth
     * element, the one thing it is known not to describe yet -- a rule of the listener's
     * that the history has since been decided against, which is not what the sequence is
     * there to tell apart. The rule it guards is still told apart: taking it out changes
     * what the sequence says, and the excuse no longer matches.
     *
     * @var array<string, list<array{0: int, 1: bool, 2: list<string>, 3?: string}>>
     */
    private const TELLS_APART = [
        // A line moved and changed by one statement tells the crate it arrived at what
        // changed (UPGRADE 1.3, and OracleChanges.md) -- the eight the long run found when
        // the rule changed.
        'a line moved and changed by one statement tells the crate it arrived at' => [
            [1, true, ['edit the article', 'flush, publishing swallowed', 'change a line', 'move a line', 'flush, refused', 'change a line', 'flush', 'flush']], // drawn by seed 149
            [6, false, ['change a line', 'flush', 'replace the crate', 'flush', 'move a line back', 'change a line', 'flush, publishing swallowed', 'flush']], // drawn by seed 638
            [3, false, ['change a line', 'move a line back', 'move a line', 'flush']], // drawn by seed 706
            [7, false, ['change a line', 'move a line', 'move a line', 'flush']], // drawn by seed 998
            [2, false, ['remove a line', 'flush', 'change a line', 'move a line', 'flush, publishing swallowed', 'flush']], // drawn by seed 1052
            [0, false, ['change a line', 'move a line', 'flush', 'flush']], // drawn by seed 1269
            [7, false, ['change a line', 'move a line', 'flush', 'add a namesake', 'replace the crate', 'flush']], // drawn by seed 1722
            [6, true, ['change a line', 'move a line', 'flush', 'flush']], // drawn by seed 2710
        ],

        // Told apart the old listener's set of rows an emptying took, asked whenever something
        // new was about to be written -- a nested flush writes its part after the sweep. The
        // rule went with reading the log (3.2a); the sequences stay, as emptyings across
        // nested, refused and swallowed flushes the replay has to get right.
        'the rows an emptying took stay taken for the rest of the operation' => [
            // drawn by seed 151
            [3, false, ['replace the crate', 'add a line', 'flush, publishing swallowed', 'change a line', 'flush, with one nested inside', 'flush']],
            // drawn by seed 378
            [0, true, ['move a line', 'replace the crate with a new line', 'flush, with one nested inside', 'replace the crate with a new line', 'flush', 'add a namesake', 'flush']],
            // drawn by seed 1087
            [6, false, ['change a line', 'replace the crate with a new line', 'add a line', 'flush, refused', 'change a line', 'flush, with one nested inside', 'flush']],
            // drawn by seed 1416
            [5, false, ['remove a line', 'move a line', 'replace the crate with a new line', 'flush, with one nested inside', 'move a line back', 'flush, publishing swallowed', 'flush']],
            // drawn by seed 2943
            [6, false, ['change a line', 'add a line', 'flush, refused', 'replace the crate', 'flush, with one nested inside', 'replace the crate', 'flush, refused', 'flush']],
        ],

        // Told apart the old emptying filtered against everything that vanished, and not only
        // against what the call running found. Gone with the rule (3.2a); kept for the same
        // reason as the ones above.
        'an emptying collected after the sweep leaves out what already vanished' => [
            // drawn by seed 949
            [2, false, ['replace the crate with a new line', 'flush, refused', 'replace the crate with a new line', 'flush, with one nested inside', 'add a line', 'flush', 'flush']],
            // drawn by seed 1516
            [6, true, ['replace the crate', 'flush, refused', 'move a line', 'replace the crate with a new line', 'move a line', 'flush, with one nested inside', 'flush']],
            // drawn by seed 2557
            [1, false, ['empty the crate', 'flush', 'replace the crate with a new line', 'add a namesake', 'flush, publishing swallowed', 'replace the crate with a new line', 'flush, with one nested inside', 'flush']],
        ],

        // The question asked after a commit reads the ELEMENTS' table for a collection mapped by
        // them. A stale deletion handed to a flush with nothing else to do asks it; with that
        // shape taken out, the emptying was dropped and a row that went was never mentioned.
        'the rows question reads an inverse collection too' => [
            // drawn by seed 2390
            [0, false, ['replace the crate with a new line', 'flush, publishing swallowed', 'add a namesake', 'flush, publishing swallowed', 'flush']],
        ],

        // NOT a rule of the listener: the narrowing of this search's own generator, which stops
        // the operations that EMPTY a collection sweeping up a phantom. These are kept so that
        // the narrowing cannot be lifted without the search saying so -- and they are not a
        // guard of anything in the bundle, which is why they are separate from the rest.
        'the emptying steps ask whether the crate holds a phantom' => [
            // drawn by seed 534
            [2, false, ['replace the crate with a new line', 'flush, with one nested inside', 'empty the crate', 'flush']],
            // drawn by seed 1038
            [0, false, ['replace the crate with a new line', 'flush, with one nested inside', 'empty the crate', 'edit the article', 'flush']],
            // drawn by seed 1599
            [4, false, ['edit the article', 'flush', 'replace the crate with a new line', 'flush, with one nested inside', 'empty the crate', 'flush, with one nested inside', 'add a namesake', 'flush', 'flush']],
            // drawn by seed 2105
            [7, false, ['move a line', 'flush, with one nested inside', 'replace the crate with a new line', 'flush, with one nested inside', 'empty the crate', 'move a line', 'flush', 'flush']],
            // drawn by seed 2972
            [4, false, ['empty the crate', 'replace the crate with a new line', 'flush, publishing swallowed', 'move a line', 'flush', 'empty the crate', 'flush']],
        ],

        // An UPDATE, and then a DELETE of the same row a listener made after it -- in that
        // flush or in one nested there: two statements reached the row, so two facts, and
        // neither cancels the other (2026-09-24, both reviewers). Drawn by the vocabulary of
        // 3.3 (2026-09-25) and measured against the two rules decided against, each put into
        // the reader and the three thousand run: that a line whose row went has no changes to
        // tell (seeds 2 and 164), and that it never arrived where an UPDATE had moved it
        // (seed 194).
        'an UPDATE and the DELETE a listener made after it are both facts' => [
            // drawn by seed 2
            [3, true, ['add a line', 'flush, and after the statement a listener removes the line', 'change a line', 'flush, and after the statement a listener removes the line', 'flush']],
            // drawn by seed 164
            [6, false, ['change a line', 'flush, and after the statement a nested one removes the line', 'flush']],
            // drawn by seed 194
            [7, false, ['move a line', 'flush, and after the statement a nested one removes the line', 'edit the article', 'replace the crate', 'flush', 'flush']],
        ],
    ];

    public function testTheSequencesThatTellARuleApartStillDescribeWhatTheRowsDid(): void
    {
        $wrong = [];

        foreach (self::TELLS_APART as $rule => $sequences) {
            foreach ($sequences as $sequence) {
                [$shape, $filtered, $steps] = $sequence;
                $excused = $sequence[3] ?? null;
                $said = $this->whatTheseStepsSaid($shape, $filtered, $steps);

                // Excused for exactly one disagreement, as KNOWN is, and checked both ways:
                // a sequence that starts describing the rows correctly has its excuse taken
                // off rather than left to cover whatever comes next.
                if (($said['says'] ?? null) !== $excused) {
                    $wrong[] = $rule."\n".($said['story'] ?? '  now described correctly; take its excuse off: '.$excused);
                }
            }
        }

        self::assertSame([], $wrong, implode("\n\n", $wrong));
    }

    /**
     * A seed's entry in KNOWN, if it has one -- read through here so that an empty list is
     * still a list to the analyser.
     *
     * @return array{says: string, because: string}|null
     */
    private static function known(int $seed): ?array
    {
        // A seed's entry is about the sequence today's vocabulary draws for it; the vocabulary
        // of 3.3 draws another one under the same number, and was green without a list.
        if (self::theVocabulary()[0] === self::VOCABULARY_3_3) {
            return null;
        }

        /** @var array<int, array{says: string, because: string}> $known */
        $known = self::KNOWN;

        return $known[$seed] ?? null;
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
        return $this->whatTheseStepsSaid(...self::drawn($seed), seed: $seed);
    }

    /**
     * What a seed draws: the world's shape, whether the filter is on, and the steps.
     *
     * Split out of running them because a seed is a function of the generator, and the
     * generator grows every round -- the steps a seed drew are what a regression has to
     * hold, or widening the vocabulary renames every one of them and none says so.
     *
     * @return array{0: int, 1: bool, 2: list<string>}
     */
    private static function drawn(int $seed): array
    {
        $drawing = new self('drawing');
        $drawing->from = $seed;
        $steps = $drawing->aSequence();

        return [$drawing->next(8), $drawing->next(4) === 0, $steps];
    }

    /**
     * @param list<string> $steps
     *
     * @return array{says: string, story: string}|null
     */
    private function whatTheseStepsSaid(int $shape, bool $filtered, array $steps, int $seed = 0): ?array
    {
        $this->setUp();
        $this->attachListener(FailurePolicy::Log);
        $this->made = 0;

        $world = $this->aWorld($shape);

        // Over the rows under test, and not over a table nothing here touches. This used
        // to enable a filter that hides Stops, in a world made of Articles, Crates and
        // CrateItems -- on for a quarter of every run and covering nothing. Hiding every
        // line is the soft-delete shape at its most extreme, and it is what makes "asked
        // underneath the application's filters" a claim this can break.
        if ($filtered) {
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
            $this->checkpoints = [];
            $this->open = [];
            $points = [$this->snapshot()];
            $this->apply($step, $world);
            $world = $this->theWorldAfter($world);
            $points = [...$points, ...$this->checkpoints, $this->snapshot()];

            for ($i = 1, $n = \count($points); $i < $n; ++$i) {
                $rows = array_merge($rows, $this->statementsBetween($points[$i - 1], $points[$i]));
            }
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
    /**
     * The words a sequence is drawn from: today's, or those of 3.3 on request.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function theVocabulary(): array
    {
        return ($_SERVER['AUDIT_MODEL_VOCABULARY'] ?? null) === '3.3'
            ? [self::VOCABULARY_3_3, self::ENDINGS_3_3]
            : [self::VOCABULARY, self::ENDINGS];
    }

    private function aSequence(): array
    {
        $steps = [];
        [$vocabulary, $endings] = self::theVocabulary();

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

            // How the flush that carries it out ends: one of the endings, or an ordinary flush
            // for the draws past them -- five of them in 3.3's eight endings, three in step 4's
            // twelve, so that each ending is drawn about as often as the ordinary flush is not.
            $ordinary = $endings === self::ENDINGS_3_3 ? 5 : 3;
            $ending = $endings[$this->next(\count($endings) + $ordinary)] ?? 'flush';

            // An ending that acts on the UPDATE of a line needs one in its flush, and left to
            // chance it had one in one flush of ten: a word drawn that often and acting that
            // rarely is a word the default run never reaches. So it brings its own -- in the
            // vocabulary of step 4 only, which draws every seed anew anyway; that of 3.3 is
            // left exactly as it drew.
            if ($endings !== self::ENDINGS_3_3 && \in_array($ending, self::ENDINGS_ON_A_LINES_UPDATE, true)) {
                $steps[] = 'change a line';
            }

            $steps[] = $ending;
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

        // Each says whether it did what it is named for: {@see self::$acted}.
        $acted = match ($step) {
            'edit the article' => (bool) ($world['article']->title = 'title '.\count($this->documents())),
            'change a line' => $lines !== [] && (bool) ($lines[0]->quantity = ($lines[0]->quantity ?? 0) + 1),
            'move a line' => $lines !== [] && (bool) ($lines[0]->crate = $world['other']),
            'move a line back' => $elsewhere !== [] && !$this->aReplacementIsWaiting && (bool) ($elsewhere[0]->crate = $crate),
            'add a line' => $this->did(fn () => $crate->add($this->lines[] = new CrateItem('SKU-added-'.++$this->made))),
            'add a namesake' => $lines !== [] && $this->did(fn () => $crate->add($this->lines[] = new CrateItem($lines[0]->sku))),
            'remove a line' => $lines !== [] && $this->did(fn () => $this->em->remove($lines[0])),
            'empty the crate' => !$this->holdsAPhantom($crate) && $this->did(fn () => $this->empty($crate)),
            'replace the crate' => !$this->holdsAPhantom($crate) && $this->did(fn () => $this->replace($crate, [])),
            'replace the crate keeping one' => !$this->holdsAPhantom($crate) && $this->did(fn () => $this->replace($crate, $lines === [] ? [] : [$lines[0]])),
            'replace the crate with a new line' => !$this->holdsAPhantom($crate) && $this->did(fn () => $this->replaceWithANewLine($crate)),
            'flush' => $this->did(fn () => $this->flush()),
            'flush, refused' => $this->did(fn () => $this->flushRefused()),
            'flush, publishing swallowed' => $this->did(fn () => $this->flushWithTheirPostFlushThrowing()),
            'flush, with one nested inside' => $this->flushWithOneNestedInside($world['article']),
            'flush, and after the statement a nested one edits the article' => $this->flushWithOneNestedAfter(static function () use ($world): bool {
                $world['article']->title = 'from after';

                return true;
            }, refused: false),
            'flush, and after the statement a nested one empties the crate' => $this->flushWithOneNestedAfter(function () use ($crate): bool {
                if ($this->holdsAPhantom($crate)) {
                    return false;
                }

                $crate->items = new ArrayCollection();

                return true;
            }, refused: false),
            'flush, and after the statement a nested one empties the crate and is refused' => $this->flushWithOneNestedAfter(function () use ($crate): bool {
                if ($this->holdsAPhantom($crate)) {
                    return false;
                }

                $crate->items = new ArrayCollection();

                return true;
            }, refused: true),
            'flush, and after the statement a listener removes the line' => $this->flushRemovingALineAfterItsStatement(nested: false),
            'flush, and after the statement a nested one removes the line' => $this->flushRemovingALineAfterItsStatement(nested: true),
            'clear the manager' => $this->did(fn () => $this->em->clear()),
            'flush, and after the statement a listener ahead of this one clears the manager' => $this->flushClearingAfterTheStatement(thenRemovingALine: false),
            'flush, and after the statement a listener clears the manager, loads a line and removes it' => $this->flushClearingAfterTheStatement(thenRemovingALine: true),
            'flush, and a savepoint of the application\'s around a statement is rolled back after this listener' => $this->flushTakingAStatementBack(aheadOfThisListener: false),
            'flush, and a savepoint of the application\'s around a statement is rolled back before this listener' => $this->flushTakingAStatementBack(aheadOfThisListener: true),
            default => throw new \LogicException('no such step: '.$step),
        };

        if ($acted) {
            $this->acted[$step] = ($this->acted[$step] ?? 0) + 1;
        }
    }

    /**
     * Runs a step that always does what it says once it is reached.
     *
     * @param \Closure(): mixed $step
     */
    private function did(\Closure $step): bool
    {
        $step();

        return true;
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
        // A line waiting for its INSERT is not one: its row does not exist YET. Asked of
        // the unit of work rather than of the id, which a sequence hands out at persist()
        // -- on PostgreSQL under DBAL 3 a line added before a refused flush has an id and no
        // row, was read as a phantom, and every step that empties the crate was skipped
        // there and nowhere else, so the one configuration drew different sequences from
        // the same seeds.
        foreach ($crate->items as $line) {
            if ($line instanceof CrateItem && $line->id !== null && !$this->em->getUnitOfWork()->isScheduledForInsert($line) && !$this->stillARow($line)) {
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
        $crate->add(new CrateItem('SKU-new-'.++$this->made));
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
    private function flushWithOneNestedInside(Article $article): bool
    {
        $this->aReplacementIsWaiting = false;

        $inner = new class($this->em, $article, $this->aroundTheNestedFlush(...)) {
            public bool $ran = false;

            public function __construct(
                private readonly \Doctrine\ORM\EntityManagerInterface $em,
                private readonly Article $article,
                private readonly \Closure $around,
            ) {
            }

            public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;
                $this->article->title = 'from inside';
                ($this->around)(fn () => $this->em->flush());
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

        return $inner->ran;
    }

    /**
     * Runs a nested flush with a reading of the tables on either side of it.
     *
     * The reading after is taken in a finally, so that a refused nested flush -- which
     * writes nothing -- still marks where it was.
     */
    private function aroundTheNestedFlush(\Closure $flush): void
    {
        $this->checkpoints[] = $this->snapshot();

        try {
            $flush();
        } finally {
            $this->checkpoints[] = $this->snapshot();
        }
    }

    /**
     * A flush that starts another one AFTER a statement has reached the row.
     *
     * The first kind of nesting here starts from preUpdate, which is before the UPDATE of
     * the entity it fires for. Four defects in two rounds of review lived after it instead,
     * in postUpdate, and none of them could be generated: an arrival is recorded in the
     * outer flush's postUpdate, a change set is spent the moment its UPDATE runs, and
     * anything a nested flush does to the outer flush's state from before that point
     * cannot reach what only exists after it. This starts from the first postUpdate the
     * outer flush raises -- after this listener's own -- so the nested flush sees the rows
     * as the outer flush has already written them.
     *
     * Refused, on request, by a listener behind this one: the shape a dying nested flush
     * takes, whose sweep of the outer flush's buckets has to be put back.
     */
    /**
     * @param \Closure(): bool $what whether it did its part
     */
    private function flushWithOneNestedAfter(\Closure $what, bool $refused): bool
    {
        $this->aReplacementIsWaiting = false;

        $inner = new class($this->em, $what, $refused, $this->aroundTheNestedFlush(...)) {
            private bool $ran = false;

            public bool $acted = false;

            public function __construct(
                private readonly \Doctrine\ORM\EntityManagerInterface $em,
                private readonly \Closure $what,
                private readonly bool $refused,
                private readonly \Closure $around,
            ) {
            }

            public function postUpdate(): void
            {
                if ($this->ran) {
                    return;
                }

                $this->ran = true;
                $this->acted = ($this->what)();

                $veto = $this->refused ? new class {
                    public function onFlush(): void
                    {
                        throw new \DomainException('the nested flush is refused');
                    }
                } : null;

                if ($veto !== null) {
                    $this->em->getEventManager()->addEventListener([Events::onFlush], $veto);
                }

                try {
                    ($this->around)(fn () => $this->em->flush());
                } catch (\DomainException) {
                    // what an application does about a listener that refuses its flush
                } finally {
                    if ($veto !== null) {
                        $this->em->getEventManager()->removeEventListener([Events::onFlush], $veto);
                    }
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postUpdate], $inner);

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Whatever the application could not complete is not this test's subject.
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::postUpdate], $inner);
        }

        return $inner->acted;
    }

    /**
     * A flush whose UPDATE of a line has run when a listener removes that line.
     *
     * Doctrine never plans an UPDATE and a DELETE of one entity in one flush -- scheduling a
     * removal takes it off the updates -- so the only road to both is a listener, after the
     * UPDATE: postUpdate of the line, behind this bundle's own. Left to the flush it is in,
     * the DELETE runs there, because executeDeletions() reads the live list; carried out by
     * a flush nested there, it runs inside it. Either way two statements reached the row,
     * and the history owes both.
     *
     * Acted when it reached what it is for -- the row there before, gone after
     * ({@see self::$acted}).
     */
    private function flushRemovingALineAfterItsStatement(bool $nested): bool
    {
        $this->aReplacementIsWaiting = false;

        $remover = new class($this->em, $nested, $this->aroundTheNestedFlush(...)) {
            /** @var int|string|null the removed line's id, read before Doctrine clears it */
            public int|string|null $removed = null;

            public function __construct(
                private readonly \Doctrine\ORM\EntityManagerInterface $em,
                private readonly bool $nested,
                private readonly \Closure $around,
            ) {
            }

            public function postUpdate(\Doctrine\ORM\Event\PostUpdateEventArgs $args): void
            {
                $line = $args->getObject();

                if ($this->removed !== null || !$line instanceof CrateItem || $line->id === null) {
                    return;
                }

                $this->removed = $line->id;
                $this->em->remove($line);

                if ($this->nested) {
                    ($this->around)(fn () => $this->em->flush());
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postUpdate], $remover);

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Whatever the application could not complete is not this test's subject.
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::postUpdate], $remover);
        }

        return $remover->removed !== null && $this->em->getConnection()->fetchOne('SELECT 1 FROM CrateItem WHERE id = ?', [$remover->removed]) === false;
    }

    /**
     * The world as the manager has it now: after a clear, every object the sequence held is
     * detached, and a step on one of them is a step on nothing. Found again by identifier --
     * under the application's filter put aside, since hiding lines is this world's filter --
     * and a line whose row is gone, or that never got one, is let go.
     *
     * @param array{article: Article, crate: Crate, other: Crate, lines: list<CrateItem>} $world
     *
     * @return array{article: Article, crate: Crate, other: Crate, lines: list<CrateItem>}
     */
    private function theWorldAfter(array $world): array
    {
        if ($this->em->contains($world['crate']) || !$this->em->isOpen()) {
            return $world;
        }

        $filters = $this->em->getFilters();
        $suspended = array_keys($filters->getEnabledFilters());

        foreach ($suspended as $name) {
            $filters->suspend($name);
        }

        try {
            $article = $this->em->find(Article::class, $world['article']->id);
            $crate = $this->em->find(Crate::class, 'C-1');
            $other = $this->em->find(Crate::class, 'C-2');
            $lines = [];

            foreach ($this->lines as $line) {
                $again = $line->id === null ? null : $this->em->find(CrateItem::class, $line->id);

                if ($again instanceof CrateItem) {
                    $lines[] = $again;
                }
            }
        } finally {
            foreach ($suspended as $name) {
                $filters->restore($name);
            }
        }

        if (!$article instanceof Article || !$crate instanceof Crate || !$other instanceof Crate) {
            throw new \LogicException('the world lost a row it never deletes');
        }

        $this->lines = $lines;
        $this->aReplacementIsWaiting = false; // a clear takes a waiting replacement with it

        return ['article' => $article, 'crate' => $crate, 'other' => $other, 'lines' => $lines];
    }

    /**
     * A flush whose first UPDATE of a line is followed, in its postUpdate, by a clear of the
     * manager -- from a listener ahead of this one -- and optionally by that line loaded
     * afresh and removed, which the same flush then carries out. Acted when the clear ran.
     */
    private function flushClearingAfterTheStatement(bool $thenRemovingALine): bool
    {
        $this->aReplacementIsWaiting = false;

        $clearing = new class($this->em, $thenRemovingALine) {
            public bool $acted = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $em, private readonly bool $thenRemovingALine)
            {
            }

            public function postUpdate(\Doctrine\ORM\Event\PostUpdateEventArgs $args): void
            {
                $line = $args->getObject();

                if ($this->acted || !$line instanceof CrateItem || $line->id === null) {
                    return;
                }

                $this->acted = true;
                $id = $line->id;
                $this->em->clear();

                if ($this->thenRemovingALine) {
                    $again = $this->em->find(CrateItem::class, $id);

                    if ($again !== null) {
                        $this->em->remove($again);
                    }
                }
            }
        };

        $events = $this->em->getEventManager();
        $there = $events->getListeners(Events::postUpdate);

        foreach ($there as $one) {
            $events->removeEventListener([Events::postUpdate], $one);
        }

        $events->addEventListener([Events::postUpdate], $clearing);

        foreach ($there as $one) {
            $events->addEventListener([Events::postUpdate], $one);
        }

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Whatever the application could not complete is not this test's subject.
        } finally {
            $events->removeEventListener([Events::postUpdate], $clearing);
        }

        return $clearing->acted;
    }

    /**
     * A flush one of whose UPDATEs the application wraps in a savepoint of its own -- opened in
     * its preUpdate -- and rolls back in its postUpdate, from a listener behind this one or
     * ahead of it. The flush goes on and commits the rest; Doctrine believes the row written.
     * Acted when the rollback ran.
     */
    private function flushTakingAStatementBack(bool $aheadOfThisListener): bool
    {
        $this->aReplacementIsWaiting = false;

        $taking = new class($this->em->getConnection()) {
            public bool $acted = false;

            private ?object $inside = null;

            public function __construct(private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
            {
                if ($this->acted || $this->inside !== null) {
                    return;
                }

                $this->inside = $args->getObject();
                $this->connection->beginTransaction();
            }

            public function postUpdate(\Doctrine\ORM\Event\PostUpdateEventArgs $args): void
            {
                if ($this->inside === null || $args->getObject() !== $this->inside) {
                    return;
                }

                $this->inside = null;
                $this->acted = true;
                $this->connection->rollBack();
            }
        };

        $events = $this->em->getEventManager();
        $there = $aheadOfThisListener ? $events->getListeners(Events::postUpdate) : [];

        foreach ($there as $one) {
            $events->removeEventListener([Events::postUpdate], $one);
        }

        $events->addEventListener([Events::preUpdate, Events::postUpdate], $taking);

        foreach ($there as $one) {
            $events->addEventListener([Events::postUpdate], $one);
        }

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Whatever the application could not complete is not this test's subject.
        } finally {
            $events->removeEventListener([Events::preUpdate, Events::postUpdate], $taking);
        }

        return $taking->acted;
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

                    // Moved and changed by one statement -- the readings are taken before every
                    // one -- and what changed belongs to the crate it arrived at: the old side
                    // is the line's, not a state of that crate's. Leaving for nowhere, the change
                    // is nobody's.
                    if ($was !== null && $was['quantity'] !== $is['quantity']) {
                        $said[] = sprintf('crate %s line %s quantity %s -> %s', $is['crate_id'], $sku, json_encode($was['quantity']), json_encode($is['quantity']));
                    }
                }
            } elseif ($was !== null && $is !== null && $was['quantity'] !== $is['quantity'] && ($is['crate_id'] ?? null) !== null) {
                // A line no crate owns has no history to be in: its change is nobody's, as
                // testAChangeInsideALineWhoseRowHasNoOwnerBelongsToNoCrate holds it.
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
