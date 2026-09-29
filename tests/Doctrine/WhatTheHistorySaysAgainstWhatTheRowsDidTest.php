<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\HideEveryLine;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Kiln;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Relay;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Tag;
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
 * **What the oracle holds the history to, and what it leaves to others** (step 5, 5.2c). It
 * checks facts: each value a statement set, the creation or removal of an audited row, and
 * who wrote it -- the actor of the flush that ran the statement, from this test's own account
 * of flushes. Compared as a multiset, it checks the completeness and the multiplicity of the
 * expected facts, with their values, events and authors -- which is what any grouping of them
 * has to start from; not of statements, since a statement that moves no value is no fact, and
 * the statements of a JOINED creation are one. How facts are divided into records, and in what order the
 * records come, is a rule of presenting them -- the contract's, not the database's, which says
 * nothing of it -- and is held by the targeted tests of the reader (WhereOneExecutionEndsTest,
 * WhatAnEntitysRowsSayOfItsRecordsTest); a second copy of those rules here would check the
 * reader against itself. The fingerprint of two runs sees a change of those properties, and
 * cannot say which run is right.
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
 *   4. **A flush started from inside another one** -- closed: the endings nest a flush before
 *      the statement and after it, refused or not, and in step 5's world under another actor.
 *      What is left of it is a connection without savepoints, where a statement taken back
 *      inside a flush makes the transaction rollback-only and the manager closes under the
 *      rest of the sequence; `nested_flush_provenance: outer` is held there by
 *      `WhoWroteItTest` and `WhoseMomentALateRecordCarriesTest`.
 *   5. **An owning many-to-many** -- closed: the tagged world (5.3) has the article's tags,
 *      and the removals' world (the default) removes a tag and the article, so the cascade a
 *      target's DELETE takes the join rows with is in its sequences.
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
     * Who is acting, as the application would say: whoever the sequence runs as, and -- in
     * step 5's world -- somebody else inside every flush nested in another. What the listener's
     * writer asks, and what this test's own observer of flushes takes as each flush begins.
     */
    private ActorResolverInterface&\stdClass $acting;

    /**
     * The flushes running, as this test sees them: where each began -- the connection's nesting
     * level at its onFlush -- and who was acting then. Its own account, kept by its own listener
     * and never read from the bundle's log: the one the oracle's authorship is read from.
     *
     * @var list<array{level: int, actor: string|null}>
     */
    private array $flushesRunning = [];

    /**
     * Who wrote the statement each reading was taken before, by the same index as the readings;
     * null for a reading no statement follows.
     *
     * @var list<string|null>
     */
    private array $checkpointActors = [];

    /**
     * The table of the statement each reading was taken before, by the same index; null for a
     * reading no statement follows. What a rollback took back is read from here.
     *
     * @var list<string|null>
     */
    private array $checkpointTables = [];

    /**
     * Which of the removals the widened world is for each reading was taken before, by the same
     * index: a tag's row, the article's row, or all of the article's links by its key alone;
     * null for any other statement. Read, like the tables, by what a rollback took back, and by
     * what a removal is reached as and what it is said to have taken.
     *
     * @var list<string|null>
     */
    private array $checkpointShapes = [];

    /**
     * How many times each combination of step 5's world was reached, across the sequences run
     * so far -- reached, not drawn: a statement of the kind it names ran, in the circumstance it
     * names, on a row the history is written about; for one taken back, a statement that ran and
     * was then undone. What the widened search is for is these, not the words that lead to them.
     *
     * @var array<string, int>
     */
    private array $reached = [];

    /** The tables a nested flush running now was asked to write by its own step; null outside one. */
    private ?array $theNestedFlushWrites = null;

    /** The table of the last statement written, while its flush is still the one running. */
    private ?string $lastTableWritten = null;

    /** Whether the kiln has been written since the outermost flush running began. */
    private bool $kilnWrittenThisFlush = false;

    /** The frame the listener of step 5's world writes through, for the ending that needs one. */
    private ?AuditFrame $frame = null;

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
            \in_array($words[0], ['INSERT', 'UPDATE', 'DELETE'], true) => $this->aWrite($words),
            default => null,
        };
    }

    /**
     * A statement that writes, about to run: read the rows before it, and count what it reaches.
     *
     * @param list<string> $words
     */
    private function aWrite(array $words): void
    {
        // The words are upper-cased; the table is named as the world names it.
        $named = trim(match ($words[0]) {
            'INSERT', 'DELETE' => $words[2] ?? '',
            default => $words[1] ?? '',
        }, '`"[]');
        $table = $named;

        foreach (self::HISTORY_TABLES as $one) {
            if (strcasecmp($one, $named) === 0) {
                $table = $one;
            }
        }
        $by = $this->whoWritesNow();
        $this->read($by, $table, match (true) {
            $words[0] !== 'DELETE' => null,
            strcasecmp($table, 'Tag') === 0 => 'DELETE Tag', // no history table: named as the SQL has it
            $table === 'Article' => 'DELETE Article',
            $table === 'article_tag' && \in_array('ARTICLE_ID', $words, true) && !\in_array('TAG_ID', $words, true) => 'DELETE the article\'s links',
            default => null,
        });

        if (!self::theWideWorld() || !\in_array($table, self::HISTORY_TABLES, true)) {
            return;
        }

        $nested = \count(array_filter($this->flushesRunning, fn (array $flush): bool => $flush['level'] < $this->em->getConnection()->getTransactionNestingLevel())) > 1;
        $kiln = $table === 'Oven' || $table === 'Kiln';

        if ($nested && $kiln) {
            $this->reach('a change of the kiln in a nested flush');
        }

        if ($nested && $table === 'Relay') {
            $this->reach('a relay written in a nested flush');
        }

        if ($nested && $by === 'bob') {
            $this->reach('a flush nested under another actor wrote a row the history is about');
        }

        if ($nested && $this->theNestedFlushWrites !== null && !\in_array($table, $this->theNestedFlushWrites, true)) {
            $this->reach('a nested flush carried out the outer flush\'s plan');
        }

        if ($table === 'article_tag' && $nested) {
            $this->reach('a link of the article\'s written in a nested flush');
        }

        if ($table === 'article_tag' && $this->kilnWrittenThisFlush) {
            $this->reach('a link of the article\'s written in a flush that changed the kiln');
        }

        if ($kiln) {
            $this->kilnWrittenThisFlush = true;
        }

        // The persister writes a JOINED change a table at a time, root first.
        if ($words[0] === 'UPDATE' && $table === 'Kiln' && $this->lastTableWritten === 'Oven') {
            $this->reach('a change of both of the kiln\'s tables in one execution');
        }

        $this->lastTableWritten = $table;
    }

    private function reach(string $combination): void
    {
        $this->reached[$combination] = ($this->reached[$combination] ?? 0) + 1;
    }

    /**
     * What a rollback took back: the statements behind the readings it drops.
     *
     * @param list<string|null> $tables
     * @param list<string|null> $shapes
     */
    private function takenBack(array $tables, bool $theWholeTransaction, array $shapes = []): void
    {
        if (!self::theWideWorld()) {
            return;
        }

        $how = $theWholeTransaction ? 'with the whole transaction' : 'to a savepoint';

        // A removal that ran and was undone is counted as that, and never as one that stood.
        if (self::theVocabulary()[0] === self::VOCABULARY) {
            foreach ($shapes as $shape) {
                if ($shape === 'DELETE Tag') {
                    $this->reach('a tag\'s removal taken back '.$how);
                }

                if ($shape === 'DELETE Article') {
                    $this->reach('the article\'s removal taken back '.$how);
                }
            }
        }

        foreach ($tables as $table) {
            if ($table === 'Oven' || $table === 'Kiln') {
                $this->reach('a change of the kiln taken back '.$how);
            }

            if ($table === 'Relay') {
                $this->reach('a relay written and taken back '.$how);
            }

            if ($table === 'article_tag' && self::theTaggedWorld()) {
                $this->reach('a link of the article\'s taken back '.$how);
            }
        }
    }

    private function read(?string $by, ?string $table = null, ?string $shape = null): void
    {
        $this->reading = true;

        try {
            $this->checkpoints[] = $this->snapshot();
            $this->checkpointActors[] = $by;
            $this->checkpointTables[] = $table;
            $this->checkpointShapes[] = $shape;
        } finally {
            $this->reading = false;
        }
    }

    /**
     * Who a statement about to run is written by: the actor of the innermost flush running at a
     * shallower level than the statement's -- each flush's statements run inside the
     * transaction it opens, one level down from where it began; one a flush nested in it runs
     * is deeper still. Outside every flush nobody's (the application's own SQL is no record).
     */
    private function whoWritesNow(): string
    {
        $level = $this->em->getConnection()->getTransactionNestingLevel();

        // With savepoints, which is what this search runs on: without them a nested flush's
        // statements are the outer flush's (nested_flush_provenance: outer) -- held by
        // WhoWroteItTest and WhoseMomentALateRecordCarriesTest in both modes, not here, since a
        // statement taken back inside a flush there makes the whole transaction rollback-only
        // and the manager closes under the rest of the sequence.
        for ($i = \count($this->flushesRunning) - 1; $i >= 0; --$i) {
            if ($this->flushesRunning[$i]['level'] < $level) {
                return $this->flushesRunning[$i]['actor'] ?? 'nobody';
            }
        }

        return 'nobody';
    }

    /**
     * A flush begins or ends, as this test's own listener hears it. What began at the level
     * now or deeper is over -- ended, refused, or had its postFlush swallowed -- and at an
     * onFlush the flush beginning is put on top, with who is acting.
     */
    private function aFlushAt(int $level, bool $begins): void
    {
        $this->flushesRunning = array_values(array_filter($this->flushesRunning, static fn (array $flush): bool => $flush['level'] < $level));

        if ($begins) {
            $this->flushesRunning[] = ['level' => $level, 'actor' => $this->acting->actor];
        }

        $this->lastTableWritten = null;

        if ($this->flushesRunning === [] || \count($this->flushesRunning) === 1 && $begins) {
            $this->kilnWrittenThisFlush = false; // an operation begins, or none is running
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
                $this->takenBack(\array_slice($this->checkpointTables, $this->open[$i][1]), theWholeTransaction: false, shapes: \array_slice($this->checkpointShapes, $this->open[$i][1]));
                $this->checkpoints = \array_slice($this->checkpoints, 0, $this->open[$i][1]);
                $this->checkpointActors = \array_slice($this->checkpointActors, 0, $this->open[$i][1]);
                $this->checkpointTables = \array_slice($this->checkpointTables, 0, $this->open[$i][1]);
                $this->checkpointShapes = \array_slice($this->checkpointShapes, 0, $this->open[$i][1]);
                array_splice($this->open, $i + 1); // the savepoint itself stays open

                return;
            }
        }
    }

    private function undoTo(int $readings, bool $all): void
    {
        $this->takenBack(\array_slice($this->checkpointTables, $readings), theWholeTransaction: $all, shapes: \array_slice($this->checkpointShapes, $readings));
        $this->checkpoints = \array_slice($this->checkpoints, 0, $readings);
        $this->checkpointActors = \array_slice($this->checkpointActors, 0, $readings);
        $this->checkpointTables = \array_slice($this->checkpointTables, 0, $readings);
        $this->checkpointShapes = \array_slice($this->checkpointShapes, 0, $readings);

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
     * side after a savepoint of the application's took its UPDATE back -- the record's value,
     * read from Doctrine's change set, which still believed the row took it. Step 5 reads an
     * entity's records from the log's facts (5.2c), and the six went with it: empty again, on
     * the same corpus, both DBALs.
     *
     * @var array<int, array{says: string, because: string}>
     */
    private const KNOWN = [];

    /**
     * What a sequence does between its flushes.
     *
     * Widened in step 5 (5.2c, 2026-09-27) by a world with a JOINED entity and relays that
     * point at each other: an entity's records are read from the log since then, and a world of
     * three tables has no hierarchy to write a table at a time and no reference Doctrine has to
     * complete. The vocabularies before it are kept, so that the corpora they drew -- three
     * thousand sequences each, green on DBAL 3 and 4 -- can still be run as they were:
     * AUDIT_MODEL_VOCABULARY=5.3, 5.2c, 5.2 (step 4's, with the world it ran in) and 3.3.
     *
     * And by removals (2026-09-29), for the cascades a target's or an owner's DELETE takes the
     * join rows with: a tag removed -- one the article holds, where there is one -- with the
     * article's collection in memory left as it is, which Doctrine takes the tag out of at the
     * commit (a canary); and the article removed, its links with it. Reached is counted from this
     * test's own readings ({@see self::COMBINATIONS_OF_THE_REMOVALS}), not from the words: a word
     * removing a tag nobody holds reaches nothing of what it is for.
     */
    private const VOCABULARY = [
        ...self::VOCABULARY_5_3,
        'remove a tag',
        'remove the article',
    ];

    /**
     * The tagged world, before the removals (5.3c's default, 2026-09-28): run on request,
     * AUDIT_MODEL_VOCABULARY=5.3, with its corpus.
     */
    private const VOCABULARY_5_3 = [
        ...self::VOCABULARY_5_2C,
        'tag the article',
        'untag the article',
        'clear the article\'s tags',
        'replace the article\'s tags',
    ];

    /**
     * Step 5's first world (5.2c, 2026-09-27): a kiln and relays -- run on request,
     * AUDIT_MODEL_VOCABULARY=5.2c, with its corpus, since the tagged world is the default.
     *
     * The tagged world ({@see self::VOCABULARY_5_3}) was the judge 5.3 was built against, and was
     * built before it: on the listener of 5.2c, 576 of its 3000 sequences disagreed with the
     * rows, every one about the article's tags -- a tag a nested flush gave lost (287), a tag's
     * move signed by the other flush's actor (194), a tag recorded that the rows never kept (90),
     * and five of both -- the collection's snapshot at its owner's events. Read from the join
     * rows (5.3), none does, and it is the default; one of each way is kept in
     * {@see self::TELLS_APART}.
     *
     * What it does not reach: no word removes a tag, or an article that holds one, so no cascade
     * a target's DELETE takes with it is in any of its sequences -- the words that do are the
     * default's ({@see self::VOCABULARY}).
     */
    private const VOCABULARY_5_2C = [
        ...self::VOCABULARY_5_2,
        'edit the kiln\'s root',
        'edit the kiln\'s subclass',
        'edit both of the kiln\'s tables',
        'create two relays pointing at each other',
        'point a relay elsewhere',
    ];

    /**
     * What step 5's world was widened to reach: each counted when it happened
     * ({@see self::$reached}), and held to the same floors as the words.
     */
    private const COMBINATIONS = [
        'a change of the kiln in a nested flush',
        // Taken back two ways, told apart: with the whole transaction -- the atomic-frame
        // ending's road -- and to a savepoint of the application's with the flush going on.
        'a change of the kiln taken back with the whole transaction',
        'a change of the kiln taken back to a savepoint',
        'a change of both of the kiln\'s tables in one execution',
        'a relay written and taken back with the whole transaction',
        'a relay written and taken back to a savepoint',
        'a relay written in a nested flush',
        'a flush nested under another actor wrote a row the history is about',
        'a nested flush carried out the outer flush\'s plan',
    ];

    /**
     * And what the tagged world was widened to reach.
     *
     * Not a link taken back to a savepoint with the flush going on -- a limit of how this
     * search opens its savepoints, around an entity's UPDATE, and not of join rows being taken
     * back: Doctrine writes a collection's join rows after every entity's UPDATE, so a
     * savepoint opened and closed around an entity's statement never holds one. Join rows are
     * taken back by the application's own transaction around the flush, which this search
     * has, and by a nested flush's savepoint when it dies after writing its own, which is a
     * guard of 5.3's, written by hand.
     */
    private const COMBINATIONS_OF_THE_TAGS = [
        'a link of the article\'s written in a nested flush',
        'a link of the article\'s taken back with the whole transaction',
        'a link of the article\'s written in a flush that changed the kiln',
    ];

    /**
     * And what the removals were added to reach, from this test's readings around each DELETE: a
     * tag the article held, its link gone with it; the article, with links it held; a removal of
     * either taken back with the application's transaction.
     */
    private const COMBINATIONS_OF_THE_REMOVALS = [
        'a tag the article held removed, its link taken by the database',
        'an article removed while it held tags',
        'a tag\'s removal taken back with the whole transaction',
    ];

    /** The tables of the rows the history is written about, entities', lines' and links'. */
    private const HISTORY_TABLES = ['Article', 'Crate', 'CrateItem', 'Oven', 'Kiln', 'Relay', 'article_tag'];

    /** How a flush of a sequence may end, besides the ordinary way. */
    private const ENDINGS = [
        ...self::ENDINGS_5_2C,
        // A nested flush started after the outer flush's statement that tags the article: the
        // join rows of one owner written by two flushes of one operation.
        'flush, and after the statement a nested one tags the article',
    ];

    /** @see self::VOCABULARY_5_2C */
    private const ENDINGS_5_2C = [
        ...self::ENDINGS_5_2,
        // A nested flush started after the outer flush's statement that changes the kiln's
        // subclass while the outer one changed its root: the shape of 5.2b's defect, which a
        // guard found and no search could.
        'flush, and after the statement a nested one edits the kiln\'s subclass',
        // The application's own transaction around the flush, rolled back, in the atomic frame
        // the README gives for it: what the flush wrote is taken back, the kiln's and the
        // relays' with it, and the frame drops what it held. The one road in this world to a
        // statement of those rows being run and then undone -- a relay's INSERT and the UPDATE
        // completing it raise no event a savepoint could be opened in.
        'flush, inside a transaction of the application\'s it rolls back, in an atomic frame',
        // A savepoint of the application's around one statement of the kiln's, or of a relay's,
        // rolled back with the flush going on: the other road to a statement of those rows taken
        // back, and the one the savepoint endings above reach for them only by luck -- they
        // open at the first entity the flush updates, which is a line's before it is theirs.
        'flush, and a savepoint of the application\'s around the kiln\'s statement is rolled back',
        'flush, and a savepoint of the application\'s around a relay\'s statement is rolled back',
    ];

    /**
     * Step 4's vocabulary (2026-09-25): 3.3's, and a clear of the manager between two
     * operations.
     */
    private const VOCABULARY_5_2 = [
        ...self::VOCABULARY_3_3,
        'clear the manager',
    ];

    /** @see self::VOCABULARY_5_2 */
    private const ENDINGS_5_2 = [
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

    /**
     * What a fingerprint of the history leaves out, each with why: nothing else. A field a
     * document gains -- an attribute, a field a later step adds -- is in the fingerprint the day
     * it appears, without anyone adding it here.
     */
    public const NOT_FINGERPRINTED = [
        'id' => 'a UUID v7 whose counter begins at a random point, with random bits after it: two runs of one sequence give two',
        'writtenAt' => 'when the transport wrote it, by the wall clock and not the change\'s (WrittenAt): two runs, two moments',
    ];

    /** What every document has, and a fingerprint refuses one without: absent is not "the same". */
    public const ALWAYS_THERE = ['objectType', 'objectId', 'event', 'loggedAt', 'source', 'changes'];

    /**
     * One history as one string: every document in the order it was written, every field but
     * the ones named in NOT_FINGERPRINTED, every list in its order. Only the order of an object's
     * keys is left out -- where a value sits among its siblings is not what it says.
     *
     * For telling two runs of the same sequences apart: before and after a change of the
     * listener that must, or must not, change what it writes.
     *
     * @param list<array<string, mixed>> $documents
     */
    public static function fingerprintOf(array $documents): string
    {
        $normal = [];

        foreach ($documents as $at => $document) {
            foreach (self::ALWAYS_THERE as $key) {
                if (!\array_key_exists($key, $document)) {
                    throw new \LogicException(sprintf('Document %d has no "%s", which a fingerprint would take for its value.', $at, $key));
                }
            }

            $normal[] = self::keysInOrder(array_diff_key($document, self::NOT_FINGERPRINTED));
        }

        return md5(json_encode($normal, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function keysInOrder(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::keysInOrder(...), $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public function testAFingerprintSeesEveryFieldOfEveryDocumentAndItsOrder(): void
    {
        // A fingerprint that hashed ['objectType', 'objectId', 'action', 'changes'] said three
        // commits running that two histories were the same, while it saw neither the event --
        // documents carry no "action" -- nor who wrote them, nor when.
        $documents = [
            ['objectType' => 'article', 'objectId' => 1, 'event' => 'update', 'loggedAt' => '2026-09-27 10:00:00', 'source' => 'alice', 'id' => 'a', 'tenant' => 'north',
                'changes' => ['title' => ['old' => 'One', 'new' => 'Two'], 'tags' => ['old' => ['php'], 'new' => ['php', 'es']]]],
            ['objectType' => 'crate', 'objectId' => 'C-1', 'event' => 'create', 'loggedAt' => '2026-09-27 10:00:00', 'source' => 'alice', 'id' => 'b', 'changes' => []],
        ];
        $fingerprint = self::fingerprintOf($documents);

        $moved = [
            'the type' => ['objectType', 'other'],
            'the id of the object' => ['objectId', 2],
            'the event' => ['event', 'create'],
            'the moment' => ['loggedAt', '2026-09-27 10:00:01'],
            'the actor' => ['source', 'bob'],
            'the changes' => ['changes', ['title' => ['old' => 'One', 'new' => 'Three']]],
            'an attribute' => ['tenant', 'south'],
            'a field it did not have' => ['somethingNew', true],
        ];

        foreach ($moved as $what => [$field, $value]) {
            $other = $documents;
            $other[0][$field] = $value;

            self::assertNotSame($fingerprint, self::fingerprintOf($other), $what.' moved, and the fingerprint did not');
        }

        $reordered = $documents;
        $reordered[0]['changes']['tags']['new'] = ['es', 'php'];
        self::assertNotSame($fingerprint, self::fingerprintOf($reordered), 'a list is in its order');
        self::assertNotSame($fingerprint, self::fingerprintOf(array_reverse($documents)), 'the documents are in theirs');

        foreach (array_keys(self::NOT_FINGERPRINTED) as $field) {
            $same = $documents;
            $same[0][$field] = 'one run';
            $same[1][$field] = 'another run';
            self::assertSame($fingerprint, self::fingerprintOf($same), $field.' is named as left out, and is not');
        }

        $same = $documents;
        $same[0] = array_reverse($same[0], true);
        $same[0]['changes'] = array_reverse($same[0]['changes'], true);
        self::assertSame($fingerprint, self::fingerprintOf($same), 'the order of an object\'s keys is not what it says');

        foreach (self::ALWAYS_THERE as $key) {
            $without = $documents;
            unset($without[1][$key]);

            try {
                self::fingerprintOf($without);
                self::fail('a document without "'.$key.'" was fingerprinted');
            } catch (\LogicException $e) {
                self::assertStringContainsString('"'.$key.'"', $e->getMessage());
            }
        }
    }

    /** The tables this reads, and the column that names each row in a statement. */
    private const TABLES = [
        'Article' => 'id',
        'Crate' => 'code',
        'CrateItem' => 'id',
    ];

    /** The tables of the audited entities, and what the history calls each. */
    private const AUDITED_TABLES = [
        'Article' => 'article',
        'Crate' => 'crate',
        'Oven' => 'oven',
        'Relay' => 'relay',
    ];

    /** And the tables of step 5's world. */
    private const TABLES_OF_THE_WIDE_WORLD = [
        'Oven' => 'id',
        'Kiln' => 'id',
        'Relay' => 'id',
    ];

    /** And of the tagged world: a join row is keyed by both its columns. */
    private const TABLES_OF_THE_TAGGED_WORLD = [
        'Tag' => 'id',
        'article_tag' => 'article_id|tag_id',
    ];

    /**
     * Every tag of the world. Named as the history names them, and alone in their names: a link
     * is said by the label of the tag it is to, so the reading refuses two tags of one label --
     * the same blind spot of the oracle's as the relays', and not to be lifted for the same
     * reason.
     *
     * @var list<Tag>
     */
    private array $tags = [];

    /**
     * The tags whose rows were gone when the step before ended, and are still: a link to one of
     * them -- one the database kept, with no foreign key to take it -- is said by the tag's
     * identifier. What names a target is the row as it stood, or the object the application
     * removed, and both are held until the flush that removed it is over; past it, a target whose
     * row is gone and which nothing holds is named by its identifier
     * (testATargetWhoseRowIsGoneAndWhichNothingHoldsIsNamedByItsIdentifier). Known here from the
     * steps, not from the listener.
     *
     * @var array<string, true>
     */
    private array $tagsGone = [];

    /** The article's identifier, and the tags': what the world is found again by after a removal taken back. */
    private ?int $articleId = null;

    /** @var list<int> */
    private array $tagIds = [];

    /** @var array<string, string> what every tag is called, by its id */
    private array $tagLabels = [];

    /**
     * The vocabulary one kept sequence runs in, over the one asked for: a sequence of the
     * tagged world's words runs in that world whatever the default is, so that what it tells
     * apart does not wait on the default changing.
     */
    private static ?string $worldOfTheSequence = null;

    /**
     * Every relay the world started with and every one a step has made since.
     *
     * @var list<Relay>
     */
    private array $relays = [];

    /**
     * What every relay has been called, by its id: a reference is said by the name of what it
     * points at, and the names of a world are its own -- the reading refuses two relays of one
     * name, so that a name always says which row.
     *
     * @var array<string, string>
     */
    private array $relayNames = [];

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
        // A number, from the first seed; or a range, "751-1500", so that the long run can be cut
        // into parts run side by side -- each seed is a sequence of its own, drawn from nothing
        // but its number, so the parts together are the run.
        $asked = (string) ($_SERVER['AUDIT_MODEL_SEEDS'] ?? '60');
        [$first, $last] = preg_match('~^(\d+)-(\d+)$~', $asked, $range) === 1 ? [(int) $range[1], (int) $range[2]] : [1, (int) $asked];
        $seeds = $last - $first + 1;
        $wrong = [];
        $listing = (bool) ($_SERVER['AUDIT_MODEL_DIFFS'] ?? false);

        $mended = [];

        for ($seed = $first; $seed <= $last; ++$seed) {
            $said = $this->whatOneSequenceSaid($seed);
            $known = self::known($seed);

            // Every disagreement, one line each, for a measurement of a world that is not yet
            // right -- listed rather than failed on, and counted by what reads them.
            if ($listing && $said !== null) {
                fwrite(\STDERR, 'DIFF '.json_encode([$seed, $said['says'], self::drawn($seed)[2]])."\n");

                continue;
            }

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

        // And what step 5's world is for, held to the same floors: a combination the search
        // stopped reaching is a world that lost its shape, whatever its words still do.
        $counted = [...$this->acted, ...$this->reached];

        if (self::theWideWorld()) {
            $words = [...$words, ...self::COMBINATIONS];
        }

        if (self::theTaggedWorld()) {
            $words = [...$words, ...self::COMBINATIONS_OF_THE_TAGS];
        }

        if (self::theVocabulary()[0] === self::VOCABULARY) {
            // A database without foreign keys takes no link with its target: there, what the
            // removal reaches is a link left behind.
            $words = [...$words, ...(($_SERVER['AUDIT_SQLITE_FOREIGN_KEYS'] ?? null) === 'off' && \Borsche\ElasticsearchAuditBundle\Tests\TestConnection::isSqlite()
                ? str_replace('its link taken by the database', 'its link left behind', self::COMBINATIONS_OF_THE_REMOVALS)
                : self::COMBINATIONS_OF_THE_REMOVALS)];
        }

        if ($seeds >= 60) {
            self::assertSame([], array_values(array_filter(
                $words,
                static fn (string $word): bool => ($counted[$word] ?? 0) === 0,
            )), sprintf('these words or combinations did nothing in %d sequences -- most likely the seeds stopped drawing them, or what they need beside them; look at the draw before the listener', $seeds));
        }

        if ($seeds >= 3000) {
            $floor = intdiv($seeds, 30);

            self::assertSame([], array_values(array_filter(
                $words,
                static fn (string $word): bool => ($counted[$word] ?? 0) < $floor,
            )), sprintf('these words or combinations were reached fewer than %d times in %d sequences: a shape of the search has died', $floor, $seeds));
        }

        fwrite(\STDERR, $_SERVER['AUDIT_MODEL_REACHED'] ?? false ? 'REACHED '.json_encode($counted)."\n" : '');

        // The seeds it ran, last: a measurement that stopped short never prints it.
        fwrite(\STDERR, $listing ? sprintf("RAN %d-%d\n", $first, $last) : '');

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

        // Relays created by a flush whose kiln UPDATE starts a nested one: Doctrine runs a
        // creation's completion after the flush's updates, so the nested flush carries it out,
        // as a statement of its own -- the creation is the outer flush's and the reference the
        // nested one's, signed by who was acting there. Found by the widened search (5.2c), and
        // measured: with a creation's completion folded across flushes -- no flush begun between,
        // and the same flush, both taken out of the reader -- the reference is signed by the
        // outer flush's actor. Runs in step 5's world, so under its vocabulary only.
        'a reference a nested flush completes is its change, signed by it' => [
            // drawn by seed 32
            [6, false, ['edit the kiln\'s root', 'change a line', 'flush, and after the statement a listener removes the line', 'move a line back', 'edit the kiln\'s root', 'flush, and after the statement a nested one edits the kiln\'s subclass', 'replace the crate with a new line', 'create two relays pointing at each other', 'edit the kiln\'s root', 'flush, and after the statement a nested one edits the kiln\'s subclass', 'flush']],
        ],

        // What 5.3 was for, one of each way the listener of 5.2c told the article's tags other than
        // the join rows said -- from the 576 of 3000 sequences of the tagged world that disagreed
        // (2026-09-27), and run in that world whatever the default is. Each carried what it said
        // then as its excuse until 5.3 read the join rows, and the excuses came off together; the
        // sequences stay, as what reading the join rows has to go on getting right.
        'the article\'s tags are what its join rows say' => [
            // a tag a nested flush gave, lost -- drawn by seed 470
            [3, false, ['remove a line', 'edit the article', 'flush, and after the statement a nested one tags the article', 'flush']],
            // a tag a nested flush wrote, signed by the outer flush's actor -- drawn by seed 716
            [4, true, ['replace the article\'s tags', 'flush, and after the statement a nested one empties the crate', 'flush']],
            // a tag a nested flush wrote, recorded again for the outer one -- drawn by seed 2089
            [0, false, ['tag the article', 'flush, and after the statement a nested one edits the article', 'flush']],
            // a tag never written -- a listener cleared the manager before the join rows -- recorded -- drawn by seed 201
            [2, true, ['tag the article', 'change a line', 'flush, and after the statement a listener ahead of this one clears the manager', 'flush']],
            // a tag taken back with the application's transaction, recorded -- drawn by seed 937
            [2, false, ['move a line back', 'flush, publishing swallowed', 'empty the crate', 'edit the kiln\'s root', 'create two relays pointing at each other', 'tag the article', 'flush, inside a transaction of the application\'s it rolls back, in an atomic frame', 'flush']],
            // both ways at once: tags written lost and one not written recorded, around a savepoint taken back -- drawn by seed 2153
            [6, false, ['replace the article\'s tags', 'flush, publishing swallowed', 'clear the article\'s tags', 'tag the article', 'change a line', 'tag the article', 'flush, and a savepoint of the application\'s around a statement is rolled back after this listener', 'flush']],
        ],
    ];

    public function testTheSequencesThatTellARuleApartStillDescribeWhatTheRowsDid(): void
    {
        $wrong = [];

        foreach (self::TELLS_APART as $rule => $sequences) {
            foreach ($sequences as $sequence) {
                [$shape, $filtered, $steps] = $sequence;

                // A sequence of the tagged world's words runs in that world, whichever is asked for.
                self::$worldOfTheSequence = array_diff($steps, [...self::VOCABULARY_5_2C, ...self::ENDINGS_5_2C, 'flush']) !== [] ? '5.3' : null;

                // A sequence of step 5's words tells its rule apart in step 5's world, and is not
                // one the vocabularies before it can draw.
                if (!self::theWideWorld() && array_diff($steps, [...self::VOCABULARY_5_2, ...self::ENDINGS_5_2, 'flush']) !== []) {
                    continue;
                }

                $excused = $sequence[3] ?? null;

                try {
                    $said = $this->whatTheseStepsSaid($shape, $filtered, $steps);
                } finally {
                    self::$worldOfTheSequence = null;
                }

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

        self::assertSame(['crate C-1 lost DUP by nobody', 'crate C-1 lost DUP by nobody'], $twice, 'two documents about one row are two statements');
        self::assertSame(['crate C-1 lost DUP by nobody'], self::missingFrom($twice, ['crate C-1 lost DUP by nobody']), 'and one of them is one too many');

        // The whole-collection form, where the count is the only thing that says a row
        // went: two lines called DUP became one, so exactly one of them left.
        self::assertSame(
            ['crate C-1 lost DUP by nobody'],
            $this->statementsIn([
                ['objectType' => 'crate', 'objectId' => 'C-1', 'changes' => ['items' => ['old' => ['DUP', 'DUP'], 'new' => ['DUP']]]],
            ]),
            'a namesake leaving a collection of namesakes',
        );

        self::assertSame([], self::missingFrom(['a', 'b'], ['b', 'a']), 'and order is not a difference');

        // An audited entity's creation and removal are statements of their own, whatever their
        // changes say: a creation with nothing to say is still one.
        self::assertSame(
            ['relay 5 created by alice', 'relay 5 name null -> "relay 1" by alice', 'relay 7 removed by bob'],
            $this->statementsIn([
                ['objectType' => 'relay', 'objectId' => 5, 'event' => 'create', 'source' => 'alice', 'changes' => ['name' => ['old' => null, 'new' => 'relay 1']]],
                ['objectType' => 'relay', 'objectId' => 7, 'event' => 'remove', 'source' => 'bob', 'changes' => []],
            ]),
            'a creation and a removal, each signed by whom its record says',
        );
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

        // Who the sequence runs as: in step 5's world one of two by the seed -- not drawn, so the
        // steps a seed draws are what they were -- and in the worlds before it the one actor they
        // always ran as.
        $this->acting = new class extends \stdClass implements ActorResolverInterface {
            public ?string $actor = 'tests';

            public function resolve(): ?string
            {
                return $this->actor;
            }
        };
        $this->acting->actor = self::theWideWorld() ? ($seed % 2 === 1 ? 'alice' : 'carol') : 'tests';
        $this->actors = $this->acting;
        $this->frame = null;

        // Through a frame in step 5's world, for the ending that begins one; a frame not begun
        // holds nothing.
        if (self::theWideWorld()) {
            $buffer = new FrameBuffer();
            $this->frame = new AuditFrame($buffer, $this->attachListenerWithFrame($buffer, FailurePolicy::Log));
        } else {
            $this->attachListener(FailurePolicy::Log);
        }

        $this->made = 0;
        $this->flushesRunning = [];
        $this->em->getEventManager()->addEventListener([Events::onFlush, Events::postFlush], new class($this->aFlushAt(...)) {
            public function __construct(private readonly \Closure $at)
            {
            }

            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $args): void
            {
                ($this->at)($args->getObjectManager()->getConnection()->getTransactionNestingLevel(), true);
            }

            public function postFlush(\Doctrine\ORM\Event\PostFlushEventArgs $args): void
            {
                ($this->at)($args->getObjectManager()->getConnection()->getTransactionNestingLevel(), false);
            }
        });

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
        $this->relayNames = [];
        $this->tagLabels = [];
        $this->tagsGone = [];
        $this->kilnWrittenThisFlush = false;
        $this->aReplacementIsWaiting = false;

        foreach ($steps as $step) {
            $trace[] = $step;

            // Around each flush of its own, not around the sequence. A line that leaves a
            // crate and comes back nets to nothing over a sequence and is two things that
            // happened, and the history is right to say both.
            $this->checkpoints = [];
            $this->checkpointActors = [];
            $this->checkpointTables = [];
            $this->checkpointShapes = [];
            $this->open = [];
            $points = [$this->snapshot()];
            $this->apply($step, $world);
            $world = $this->theWorldAfter($world);
            $world = $this->theWorldAsTheRowsHaveIt($world);
            $points = [...$points, ...$this->checkpoints, $this->snapshot()];
            $by = [null, ...$this->checkpointActors, null];
            $shapes = [null, ...$this->checkpointShapes, null];

            // What changed between two readings is the statement's the first was taken before,
            // and the flush running it is whose record it is.
            $facts = [];

            for ($i = 1, $n = \count($points); $i < $n; ++$i) {
                $facts[$i] = $this->statementsBetween($points[$i - 1], $points[$i], $by[$i - 1]);
            }

            if (self::theVocabulary()[0] === self::VOCABULARY) {
                $facts = $this->withWhatARemovalTook($points, $shapes, $facts);
            }

            foreach ($facts as $one) {
                $rows = array_merge($rows, $one);
            }

            // Past this step, a tag whose row is gone is named by its identifier.
            $this->tagsGone = array_map(static fn (): bool => true, array_diff_key($this->tagLabels, $points[\count($points) - 1]['Tag'] ?? []));
        }

        sort($rows);
        $history = $this->statementsIn($this->documents());

        // What the sequence asked of its relays, and nothing more: relays created pointing at
        // each other and never pointed elsewhere have a creation each and no update -- the
        // UPDATE Doctrine completes a creation with is part of it. Asked of the steps, which
        // are the scenario's, and not of any rule of the listener's; and only where the flushes
        // are plain ones -- Doctrine runs a creation's completion after the flush's updates, so
        // a flush nested in one of those carries it out, as its own statement.
        if (self::relaysCreatedAndLeftAlone($steps)) {
            foreach ($this->documents() as $document) {
                if ($document['objectType'] === 'relay' && $document['event'] === 'update') {
                    $history[] = sprintf('relay %s updated, which no step asked for', $document['objectId']);
                }
            }

            sort($history);
        }

        // What the sequence published and what it cost, for a measurement that holds two runs of
        // a corpus against each other -- the documents, and the statements run for them.
        fwrite(\STDERR, $_SERVER['AUDIT_MODEL_FINGERPRINTS'] ?? false ? sprintf("FP %d %s %d %d\n", $seed, self::fingerprintOf($this->documents()), \count($this->queries), \count($this->documents())) : '');

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
     * The words a sequence is drawn from: the tagged world's by default (5.3), or those of
     * 5.2c, 5.2 or 3.3 on request -- each corpus run in the world it was drawn in.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function theVocabulary(): array
    {
        return match (self::$worldOfTheSequence ?? $_SERVER['AUDIT_MODEL_VOCABULARY'] ?? null) {
            '3.3' => [self::VOCABULARY_3_3, self::ENDINGS_3_3],
            '5.2' => [self::VOCABULARY_5_2, self::ENDINGS_5_2],
            '5.2c' => [self::VOCABULARY_5_2C, self::ENDINGS_5_2C],
            '5.3' => [self::VOCABULARY_5_3, self::ENDINGS],
            default => [self::VOCABULARY, self::ENDINGS],
        };
    }

    /**
     * Whether the sequences run in the world of step 5, with a kiln and relays: the corpora of
     * 3.3 and 5.2 run in the world they were drawn in, reading the tables they read.
     */
    private static function theWideWorld(): bool
    {
        return \in_array(self::theVocabulary()[0], [self::VOCABULARY, self::VOCABULARY_5_3, self::VOCABULARY_5_2C], true);
    }

    /** Whether the world has the article's tags too: today's and 5.3's, and not 5.2c's. */
    private static function theTaggedWorld(): bool
    {
        return self::theVocabulary()[0] === self::VOCABULARY || self::theVocabulary()[0] === self::VOCABULARY_5_3;
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

            // And the kiln's nested change needs the outer flush's change of the root beside it
            // to be the shape it is for.
            if ($ending === 'flush, and after the statement a nested one edits the kiln\'s subclass') {
                $steps[] = 'edit the kiln\'s root';
            }

            if ($ending === 'flush, and a savepoint of the application\'s around the kiln\'s statement is rolled back') {
                $steps[] = 'edit the kiln\'s root';
            }

            if ($ending === 'flush, and a savepoint of the application\'s around a relay\'s statement is rolled back') {
                $steps[] = 'point a relay elsewhere';
            }

            // And the rolled-back transaction needs the rows it is there to take back.
            if ($ending === 'flush, inside a transaction of the application\'s it rolls back, in an atomic frame') {
                $steps[] = 'edit the kiln\'s root';
                $steps[] = 'create two relays pointing at each other';

                if ($endings === self::ENDINGS) {
                    $steps[] = 'tag the article';
                }

                // And a tag's removal to take back with them, the cascade's join row too.
                if ($vocabulary === self::VOCABULARY) {
                    $steps[] = 'remove a tag';
                }
            }

            // The nested flush's link needs the outer flush's UPDATE to start from, and the
            // savepoint ending of a line's may as well take a link back as a line.
            if ($ending === 'flush, and after the statement a nested one tags the article') {
                $steps[] = 'edit the article';
            }

            if ($endings === self::ENDINGS && str_starts_with($ending, 'flush, and a savepoint of the application\'s around a statement')) {
                $steps[] = 'tag the article';
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
     * @return array{article: Article, crate: Crate, other: Crate, lines: list<CrateItem>, kiln: Kiln|null}
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

        // Step 5's: a JOINED entity, and relays to point at each other -- with names of their
        // own, since a reference is said by the name of what it points at.
        $kiln = null;
        $this->relays = [];

        if (self::theWideWorld()) {
            $this->em->persist($kiln = new Kiln());
            $this->em->persist($this->relays[] = new Relay('hub'));
            $this->em->persist($this->relays[] = new Relay('spare'));
        }

        // The tagged world's: three tags, the article carrying the first.
        $this->tags = [];

        if (self::theTaggedWorld()) {
            foreach (['php', 'es', 'db'] as $label) {
                $this->em->persist($this->tags[] = new Tag($label));
            }

            $article->tags->add($this->tags[0]);
        }

        $this->em->flush();
        $this->articleId = $article->id;
        $this->tagIds = array_values(array_filter(array_map(static fn (Tag $tag): ?int => $tag->id, $this->tags), static fn (?int $id): bool => $id !== null));

        $this->lines = $lines;

        return ['article' => $article, 'crate' => $crate, 'other' => $other, 'lines' => $lines, 'kiln' => $kiln];
    }

    /**
     * @param array{article: Article, crate: Crate, other: Crate, lines: list<CrateItem>, kiln: Kiln|null} $world
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
            'edit the article' => $world['article'] !== null && (bool) ($world['article']->title = 'title '.\count($this->documents())),
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
            'flush, with one nested inside' => $this->flushWithOneNestedInside($world['article'] ?? new Article('gone')),
            'flush, and after the statement a nested one edits the article' => $this->flushWithOneNestedAfter(static function () use ($world): bool {
                if ($world['article'] === null) {
                    return false;
                }

                $world['article']->title = 'from after';

                return true;
            }, refused: false, itWrites: ['Article']),
            'flush, and after the statement a nested one empties the crate' => $this->flushWithOneNestedAfter(function () use ($crate): bool {
                if ($this->holdsAPhantom($crate)) {
                    return false;
                }

                $crate->items = new ArrayCollection();

                return true;
            }, refused: false, itWrites: ['CrateItem']),
            'flush, and after the statement a nested one empties the crate and is refused' => $this->flushWithOneNestedAfter(function () use ($crate): bool {
                if ($this->holdsAPhantom($crate)) {
                    return false;
                }

                $crate->items = new ArrayCollection();

                return true;
            }, refused: true, itWrites: ['CrateItem']),
            'flush, and after the statement a listener removes the line' => $this->flushRemovingALineAfterItsStatement(nested: false),
            'flush, and after the statement a nested one removes the line' => $this->flushRemovingALineAfterItsStatement(nested: true),
            'clear the manager' => $this->did(fn () => $this->em->clear()),
            'edit the kiln\'s root' => $world['kiln'] !== null && (bool) ($world['kiln']->label = 'label '.++$this->made),
            'edit the kiln\'s subclass' => $world['kiln'] !== null && (bool) ($world['kiln']->heat += 10),
            'edit both of the kiln\'s tables' => $world['kiln'] !== null && $this->did(function () use ($world): void {
                \assert($world['kiln'] !== null);
                $world['kiln']->label = 'label '.++$this->made;
                $world['kiln']->heat += 10;
            }),
            'create two relays pointing at each other' => self::theWideWorld() && $this->did(function (): void {
                $one = new Relay('relay '.++$this->made);
                $other = new Relay('relay '.++$this->made);
                $one->next = $other;
                $other->next = $one;
                $this->em->persist($this->relays[] = $one);
                $this->em->persist($this->relays[] = $other);
            }),
            'point a relay elsewhere' => $this->pointARelayElsewhere(),
            'tag the article' => $this->tagTheArticle($world['article']),
            'remove a tag' => $this->removeATag($world['article']),
            'remove the article' => $world['article'] !== null && $this->em->contains($world['article']) && $this->did(fn () => $this->em->remove($world['article'])),
            'untag the article' => self::theTaggedWorld() && $world['article'] !== null && !$world['article']->tags->isEmpty() && (bool) $world['article']->tags->removeElement($world['article']->tags->first()),
            'clear the article\'s tags' => self::theTaggedWorld() && $world['article'] !== null && !$world['article']->tags->isEmpty() && $this->did(fn () => $world['article']->tags->clear()),
            'replace the article\'s tags' => self::theTaggedWorld() && $world['article'] !== null && $this->tags !== [] && $this->did(function () use ($world): void {
                $world['article']->tags = new ArrayCollection([$this->tags[\count($this->tags) - 1]]);
            }),
            'flush, and after the statement a nested one tags the article' => $this->flushWithOneNestedAfter(fn (): bool => $this->tagTheArticle($world['article']), refused: false, itWrites: ['article_tag']),
            'flush, inside a transaction of the application\'s it rolls back, in an atomic frame' => $this->flushRolledBackInAnAtomicFrame(),
            'flush, and a savepoint of the application\'s around the kiln\'s statement is rolled back' => $this->flushTakingAStatementBack(aheadOfThisListener: false, only: Kiln::class),
            'flush, and a savepoint of the application\'s around a relay\'s statement is rolled back' => $this->flushTakingAStatementBack(aheadOfThisListener: false, only: Relay::class),
            'flush, and after the statement a nested one edits the kiln\'s subclass' => $world['kiln'] !== null && $this->flushWithOneNestedAfter(static function () use ($world): bool {
                \assert($world['kiln'] !== null);
                $world['kiln']->heat += 1;

                return true;
            }, refused: false, itWrites: ['Kiln']),
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
     * The README's recipe for an application that owns a wider transaction: an atomic frame, the
     * transaction, the flush, and -- here -- the rollback, a clear of what Doctrine believes
     * written, and the frame reset. Acted when it rolled a flush back.
     */
    private function flushRolledBackInAnAtomicFrame(): bool
    {
        if ($this->frame === null) {
            return false;
        }

        $this->aReplacementIsWaiting = false;
        $connection = $this->em->getConnection();
        $this->frame->begin(atomic: true);
        $connection->beginTransaction();

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Whatever the application could not complete is not this test's subject.
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->em->clear();
            $this->frame->reset();
        }

        return true;
    }

    /**
     * Whether a sequence creates relays pointing at each other and leaves them to plain flushes:
     * never pointed elsewhere, and no flush after the first creation but ordinary, refused or
     * with its publishing swallowed.
     *
     * @param list<string> $steps
     */
    private static function relaysCreatedAndLeftAlone(array $steps): bool
    {
        $first = array_search('create two relays pointing at each other', $steps, true);

        if ($first === false || \in_array('point a relay elsewhere', $steps, true)) {
            return false;
        }

        foreach (\array_slice($steps, (int) $first) as $step) {
            if (str_starts_with($step, 'flush') && !\in_array($step, ['flush', 'flush, refused', 'flush, publishing swallowed'], true)) {
                return false;
            }
        }

        return true;
    }

    /** The article gains the first tag of the world it does not carry. Acted when there was one. */
    private function tagTheArticle(?Article $article): bool
    {
        if (!self::theTaggedWorld() || $article === null) {
            return false;
        }

        foreach ($this->tags as $tag) {
            if ($this->em->contains($tag) && !$article->tags->contains($tag)) {
                $article->tags->add($tag);

                return true;
            }
        }

        return false;
    }

    /**
     * A tag removed -- one the article holds, where there is one -- and nothing else: the
     * article's collection in memory is left as it is, and its join row is the database's to
     * take. Acted when there was a tag to remove.
     */
    private function removeATag(?Article $article): bool
    {
        if (self::theVocabulary()[0] !== self::VOCABULARY) {
            return false;
        }

        $alive = array_values(array_filter($this->tags, fn (Tag $tag): bool => $this->em->contains($tag)));
        $held = array_values(array_filter($alive, static fn (Tag $tag): bool => $article !== null && $article->tags->contains($tag)));
        $tag = $held[0] ?? $alive[0] ?? null;

        if ($tag === null) {
            return false;
        }

        $this->em->remove($tag);

        return true;
    }

    /**
     * The world as the rows have it (the removals' world only): a row a removal took and a
     * rollback put back is the world's again, found by its identifier, and one the removal
     * took for good is gone from it. Doctrine forgets an entity it removed at the commit that
     * carried the removal out, whether or not the application's transaction then took it back.
     *
     * @param array{article: Article|null, crate: Crate, other: Crate, lines: list<CrateItem>, kiln: Kiln|null} $world
     *
     * @return array{article: Article|null, crate: Crate, other: Crate, lines: list<CrateItem>, kiln: Kiln|null}
     */
    private function theWorldAsTheRowsHaveIt(array $world): array
    {
        if (self::theVocabulary()[0] !== self::VOCABULARY || !$this->em->isOpen()) {
            return $world;
        }

        $uow = $this->em->getUnitOfWork();

        if ($this->articleId !== null && ($world['article'] === null || !$uow->isInIdentityMap($world['article']))) {
            $found = $this->em->find(Article::class, $this->articleId);
            $world['article'] = $found instanceof Article ? $found : null;
        }

        $tags = [];

        foreach ($this->tagIds as $id) {
            $tag = array_values(array_filter($this->tags, static fn (Tag $one): bool => $one->id === $id))[0] ?? null;

            if ($tag === null || !$uow->isInIdentityMap($tag)) {
                $tag = $this->em->find(Tag::class, $id);
            }

            if ($tag instanceof Tag) {
                $tags[] = $tag;
            }
        }

        $this->tags = $tags;

        return $world;
    }

    /**
     * The first relay the manager still has points at the next one, or -- when it already does
     * -- at the one after, or at nothing. Acted when there were two to choose between.
     */
    private function pointARelayElsewhere(): bool
    {
        $alive = array_values(array_filter(
            $this->relays,
            fn (Relay $relay): bool => $this->em->contains($relay)
                && !$this->em->getUnitOfWork()->isScheduledForDelete($relay)
                && ($relay->id === null || $this->em->getUnitOfWork()->isScheduledForInsert($relay) || $this->em->getConnection()->fetchOne('SELECT 1 FROM Relay WHERE id = ?', [$relay->id]) !== false),
        ));

        if (\count($alive) < 2) {
            return false;
        }

        $alive[0]->next = $alive[0]->next === $alive[1] ? ($alive[2] ?? null) : $alive[1];

        return true;
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
                ($this->around)(fn () => $this->em->flush(), ['Article']);
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
    /**
     * @param list<string>|null $itWrites the tables the nested flush's own step writes: what it
     *                                    writes besides is the outer flush's plan, carried out
     */
    private function aroundTheNestedFlush(\Closure $flush, ?array $itWrites = null): void
    {
        $this->checkpoints[] = $this->snapshot();
        $this->checkpointActors[] = null;
        $this->checkpointTables[] = null;
        $this->checkpointShapes[] = null;
        $outer = $this->acting->actor;
        $this->theNestedFlushWrites = $itWrites;

        // In step 5's world a flush nested in another runs as somebody else: whose records the
        // statements it carries out are, the outer flush's remaining plan among them, is what
        // the oracle can then tell apart.
        if (self::theWideWorld()) {
            $this->acting->actor = 'bob';
        }

        try {
            $flush();
        } finally {
            $this->acting->actor = $outer;
            $this->theNestedFlushWrites = null;
            $this->checkpoints[] = $this->snapshot();
            $this->checkpointActors[] = null;
            $this->checkpointTables[] = null;
            $this->checkpointShapes[] = null;
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
    private function flushWithOneNestedAfter(\Closure $what, bool $refused, ?array $itWrites = null): bool
    {
        $this->aReplacementIsWaiting = false;

        $inner = new class($this->em, $what, $refused, $this->aroundTheNestedFlush(...), $itWrites) {
            private bool $ran = false;

            public bool $acted = false;

            public function __construct(
                private readonly \Doctrine\ORM\EntityManagerInterface $em,
                private readonly \Closure $what,
                private readonly bool $refused,
                private readonly \Closure $around,
                private readonly ?array $itWrites,
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
                    ($this->around)(fn () => $this->em->flush(), $this->itWrites);
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
                    ($this->around)(fn () => $this->em->flush(), ['CrateItem']);
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
     * @param array{article: Article, crate: Crate, other: Crate, lines: list<CrateItem>, kiln: Kiln|null} $world
     *
     * @return array{article: Article, crate: Crate, other: Crate, lines: list<CrateItem>, kiln: Kiln|null}
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
            // By the identifier the world was built with: Doctrine clears a generated one off the
            // object it removed.
            $articleId = $this->articleId ?? $world['article']?->id;
            $article = $articleId === null ? null : $this->em->find(Article::class, $articleId);
            $crate = $this->em->find(Crate::class, 'C-1');
            $other = $this->em->find(Crate::class, 'C-2');
            $lines = [];

            foreach ($this->lines as $line) {
                $again = $line->id === null ? null : $this->em->find(CrateItem::class, $line->id);

                if ($again instanceof CrateItem) {
                    $lines[] = $again;
                }
            }

            $kiln = $world['kiln'] === null ? null : $this->em->find(Kiln::class, $world['kiln']->id);
            $tags = [];

            foreach ($this->tags as $tag) {
                $again = $tag->id === null ? null : $this->em->find(Tag::class, $tag->id);

                if ($again instanceof Tag) {
                    $tags[] = $again;
                }
            }
            $relays = [];

            foreach ($this->relays as $relay) {
                $again = $relay->id === null ? null : $this->em->find(Relay::class, $relay->id);

                if ($again instanceof Relay) {
                    $relays[] = $again;
                }
            }
        } finally {
            foreach ($suspended as $name) {
                $filters->restore($name);
            }
        }

        // The article is the one row a word removes (the removals' world): gone, it is gone.
        $theArticleMayGo = self::theVocabulary()[0] === self::VOCABULARY;

        if ((!$article instanceof Article && !$theArticleMayGo) || !$crate instanceof Crate || !$other instanceof Crate || ($world['kiln'] !== null && !$kiln instanceof Kiln)) {
            throw new \LogicException('the world lost a row it never deletes');
        }

        $this->lines = $lines;
        $this->relays = $relays;
        $this->tags = $tags;
        $this->aReplacementIsWaiting = false; // a clear takes a waiting replacement with it

        return ['article' => $article instanceof Article ? $article : null, 'crate' => $crate, 'other' => $other, 'lines' => $lines, 'kiln' => $kiln instanceof Kiln ? $kiln : null];
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
    /**
     * @param class-string|null $only the class whose UPDATE the savepoint is opened around; the
     *                                first entity the flush updates, when none
     */
    private function flushTakingAStatementBack(bool $aheadOfThisListener, ?string $only = null): bool
    {
        $this->aReplacementIsWaiting = false;

        $taking = new class($this->em->getConnection(), $only) {
            public bool $acted = false;

            private ?object $inside = null;

            /** @param class-string|null $only */
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection, private readonly ?string $only)
            {
            }

            public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
            {
                if ($this->acted || $this->inside !== null || ($this->only !== null && !$args->getObject() instanceof $this->only)) {
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

        $tables = self::theWideWorld() ? [...self::TABLES, ...self::TABLES_OF_THE_WIDE_WORLD] : self::TABLES;

        if (self::theTaggedWorld()) {
            $tables = [...$tables, ...self::TABLES_OF_THE_TAGGED_WORLD];
        }

        foreach ($tables as $table => $key) {
            foreach ($this->em->getConnection()->fetchAllAssociative('SELECT * FROM '.$table) as $row) {
                $taken[$table][implode('|', array_map(static fn (string $column): string => (string) $row[$column], explode('|', $key)))] = $row;

                if ($table === 'Tag') {
                    $this->tagLabels[(string) $row['id']] = (string) $row['label'];
                }

                if ($table === 'CrateItem') {
                    $this->skus[(string) $row['id']] = (string) $row['sku'];
                }

                if ($table === 'Relay') {
                    $this->relayNames[(string) $row['id']] = (string) $row['name'];
                }
            }
        }

        // A blind spot of this oracle's, not the bundle's, which binds a reference by its key: the
        // oracle says a reference by the name of the relay it points at, so two relays of one
        // name would be two rows it could not tell apart. Refused, then, and not to be lifted as
        // redundant -- the namesake lines of the crate were put into the world for the opposite
        // reason, where the one telling rows apart by what they are shown as was the bundle.
        $names = array_column($taken['Relay'] ?? [], 'name');

        if (\count($names) !== \count(array_unique($names))) {
            throw new \LogicException('two relays of one name: a reference said by the name would not say which row');
        }

        $labels = array_column($taken['Tag'] ?? [], 'label');

        if (\count($labels) !== \count(array_unique($labels))) {
            throw new \LogicException('two tags of one label: a link said by the label would not say which row');
        }

        return $taken;
    }

    /**
     * What an owner's removal took is its removal's (5.3c): the article's links its row's DELETE
     * took with it -- the cascade, in the same statement -- and the ones a statement taking all of
     * them by the article's key alone took right before it, as Doctrine does where its join
     * columns do not cascade. Nothing else: a link taken earlier, by a target's DELETE or on its
     * own, or by such a statement whose owner's DELETE did not stand -- a rollback dropped its
     * reading -- stays a fact of its own. The guards of the rule are the listener's own:
     * testAnOwnersRowGoneAndBackInOneFlushStartsItsListAgain and
     * testLinksTakenRightBeforeAnOwnersDeleteTakenBackStayTheirOwnMove.
     *
     * And what the removals reach, counted from the same readings.
     *
     * @param list<array<string, array<string, array<string, mixed>>>> $points
     * @param list<string|null>                                        $shapes the removal each reading was taken before
     * @param array<int, list<string>>                                 $facts  what each statement did, by the reading after it
     *
     * @return array<int, list<string>>
     */
    private function withWhatARemovalTook(array $points, array $shapes, array $facts): array
    {
        $linksOf = static function (array $point, string $column, string $id): array {
            return array_filter($point['article_tag'] ?? [], static fn (array $row): bool => (string) $row[$column] === $id);
        };

        foreach ($facts as $i => $said) {
            $before = $points[$i - 1];
            $after = $points[$i];
            $shape = $shapes[$i - 1] ?? null;

            if ($shape === 'DELETE Tag') {
                foreach (array_diff_key($before['Tag'] ?? [], $after['Tag'] ?? []) as $tag => $ignored) {
                    $held = $linksOf($before, 'tag_id', (string) $tag);

                    if ($held !== []) {
                        $this->reach(array_intersect_key($held, $after['article_tag'] ?? []) === []
                            ? 'a tag the article held removed, its link taken by the database'
                            : 'a tag the article held removed, its link left behind');
                    }
                }
            }

            if ($shape !== 'DELETE Article') {
                continue;
            }

            foreach (array_diff_key($before['Article'] ?? [], $after['Article'] ?? []) as $article => $ignored) {
                $untagged = sprintf('article %s untagged ', $article);
                $mine = static fn (string $fact): bool => str_starts_with($fact, $untagged);

                if ($linksOf($before, 'article_id', (string) $article) !== []) {
                    $this->reach('an article removed while it held tags');
                }

                $facts[$i] = array_values(array_filter($facts[$i], static fn (string $fact): bool => !$mine($fact)));

                // All of its links by its key alone, the statement right before, and what that
                // statement did nothing but that: the removal's too.
                if ($i >= 2 && ($shapes[$i - 2] ?? null) === 'DELETE the article\'s links'
                    && $linksOf($before, 'article_id', (string) $article) === []
                    && $linksOf($points[$i - 2], 'article_id', (string) $article) !== []
                    && array_filter($facts[$i - 1], static fn (string $fact): bool => !$mine($fact)) === []
                ) {
                    $facts[$i - 1] = [];
                    $this->reach('an article removed right after all its links were taken');
                }
            }
        }

        return $facts;
    }

    /**
     * What the rows did, as statements.
     *
     * Each with who wrote it: a fact is its value and its author together, or Alice and Bob
     * swapped between two changes would go unseen.
     *
     * @param array<string, array<string, array<string, mixed>>> $before
     * @param array<string, array<string, array<string, mixed>>> $after
     * @param string|null                                        $by    who wrote the statement between them
     *
     * @return list<string>
     */
    private function statementsBetween(array $before, array $after, ?string $by = 'nobody'): array
    {
        $said = [];

        // An audited entity's row appearing between two readings is its creation, and going is
        // its removal: readings are taken before every statement, so a row created and removed
        // in one operation is both. A kiln is its root's row; a line is not audited, and what it
        // did is its crate's.
        foreach (self::AUDITED_TABLES as $table => $type) {
            foreach (($before[$table] ?? []) + ($after[$table] ?? []) as $id => $ignored) {
                $was = isset($before[$table][$id]);
                $is = isset($after[$table][$id]);

                if ($was !== $is) {
                    $said[] = sprintf('%s %s %s', $type, $id, $is ? 'created' : 'removed');
                }
            }
        }

        foreach (($before['Article'] ?? []) + ($after['Article'] ?? []) as $id => $ignored) {
            $was = $before['Article'][$id]['title'] ?? null;
            $is = $after['Article'][$id]['title'] ?? null;

            // A removal is its row going, and no value of it: a remove record carries none.
            if ($was !== $is && isset($after['Article'][$id])) {
                $said[] = sprintf('article %s title %s -> %s', $id, json_encode($was), json_encode($is));
            }
        }

        // A kiln is one entity in two tables: what either of its rows did is said about it.
        foreach (($before['Oven'] ?? []) + ($after['Oven'] ?? []) as $id => $ignored) {
            $was = ($before['Oven'][$id] ?? []) + ($before['Kiln'][$id] ?? []);
            $is = ($after['Oven'][$id] ?? []) + ($after['Kiln'][$id] ?? []);

            foreach (['label', 'site', 'firing', 'heat'] as $column) {
                if (($was[$column] ?? null) !== ($is[$column] ?? null)) {
                    $said[] = sprintf('oven %s %s %s -> %s', $id, $column, json_encode($was[$column] ?? null), json_encode($is[$column] ?? null));
                }
            }
        }

        // A relay's reference is said by the name of the relay it points at.
        foreach (($before['Relay'] ?? []) + ($after['Relay'] ?? []) as $id => $ignored) {
            $was = $before['Relay'][$id] ?? null;
            $is = $after['Relay'][$id] ?? null;

            if (($was['name'] ?? null) !== ($is['name'] ?? null)) {
                $said[] = sprintf('relay %s name %s -> %s', $id, json_encode($was['name'] ?? null), json_encode($is['name'] ?? null));
            }

            $from = ($was['next_id'] ?? null) === null ? null : (string) $was['next_id'];
            $to = ($is['next_id'] ?? null) === null ? null : (string) $is['next_id'];

            if ($from !== $to) {
                $said[] = sprintf('relay %s next %s -> %s', $id, json_encode($from === null ? null : $this->relayNames[$from] ?? '?'), json_encode($to === null ? null : $this->relayNames[$to] ?? '?'));
            }
        }

        // A link of the article's is its join row: one appearing is a tag it gained, one going a
        // tag it lost -- said by the tag's label.
        foreach (($before['article_tag'] ?? []) + ($after['article_tag'] ?? []) as $link => $row) {
            $was = isset($before['article_tag'][$link]);
            $is = isset($after['article_tag'][$link]);

            if ($was !== $is) {
                $tag = (string) $row['tag_id'];
                $said[] = sprintf('article %s %s %s', $row['article_id'], $is ? 'tagged' : 'untagged', json_encode(isset($this->tagsGone[$tag]) ? $tag : ($this->tagLabels[$tag] ?? '?')));
            }
        }

        // (the lines follow; what the rows did is signed below, all of it)
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

        if ($said !== [] && $by === null) {
            throw new \LogicException('the rows moved between two readings with no statement between them: '.implode('; ', $said));
        }

        return array_map(static fn (string $one): string => $one.' by '.$by, $said);
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
            $from = \count($said);

            if (\in_array($type, self::AUDITED_TABLES, true) && \in_array($document['event'] ?? null, ['create', 'remove'], true)) {
                $said[] = sprintf('%s %s %s', $type, $id, $document['event'] === 'create' ? 'created' : 'removed');
            }

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

                // The tags as the whole list moved: what it gained and lost, counting repeats. Its
                // order is the targeted tests' to hold; the facts are these.
                if ($type === 'article' && $field === 'tags') {
                    foreach (self::missingFrom((array) $new, (array) $old) as $label) {
                        $said[] = sprintf('article %s tagged %s', $id, json_encode($label));
                    }

                    foreach (self::missingFrom((array) $old, (array) $new) as $label) {
                        $said[] = sprintf('article %s untagged %s', $id, json_encode($label));
                    }

                    continue;
                }

                if ($type === 'oven' || ($type === 'relay' && ($field === 'name' || $field === 'next'))) {
                    $said[] = sprintf('%s %s %s %s -> %s', $type, $id, $field, json_encode($old), json_encode($new));

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

            // Signed by whom the record says.
            for ($i = $from, $n = \count($said); $i < $n; ++$i) {
                $said[$i] .= ' by '.(\is_string($document['source'] ?? null) ? $document['source'] : 'nobody');
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
