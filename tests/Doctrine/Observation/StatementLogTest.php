<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use PHPUnit\Framework\TestCase;

/**
 * The log's own rules, fed by hand in the shapes the connection was measured producing.
 *
 * Every sequence here was seen on the wire -- MySQL 8, Postgres 16 and SQLite, under DBAL 3
 * and 4 -- when a flush nested inside another wrote, died, or was refused. The connection
 * adapters that feed the log in production are tested against a real connection; this is
 * what the log makes of what they report.
 */
final class StatementLogTest extends TestCase
{
    /**
     * DBAL's count is int|numeric-string: a string when a driver's count is past what an int
     * holds. Kept as the int, or as not known -- never cut to PHP_INT_MAX, never nought.
     *
     * @return iterable<string, array{int|string|null, int|null}>
     */
    public static function counts(): iterable
    {
        yield 'none' => [0, 0];
        yield 'none, as a string' => ['0', 0];
        yield 'some' => [5, 5];
        yield 'some, as a string' => ['5', 5];
        yield 'the most an int holds, as a string' => [(string) \PHP_INT_MAX, \PHP_INT_MAX];
        yield 'one past it' => ['9223372036854775808', null];
        yield 'far past it' => ['99999999999999999999', null];
        yield 'no count' => [null, null];
        yield 'the most an int holds, behind a space and zeros' => [' 0009223372036854775807', \PHP_INT_MAX];
        yield 'below none' => ['-1', null];
        yield 'not a whole number' => ['1.5', null];
        yield 'not a number at all' => ['5 rows', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('counts')]
    public function testACountIsKeptAsAnIntOrAsNotKnown(int|string|null $given, ?int $kept): void
    {
        $log = new StatementLog();
        $at = $log->executed('DELETE FROM CrateItem WHERE id = ?', [1 => 2], $given);

        self::assertNotNull($at);
        $statement = $log->statement($at);
        self::assertNotNull($statement);
        self::assertSame($kept, $statement['affected']);
    }

    public function testAStatementOutsideEveryFrameIsNotVoidedByTheOnlyFrameRollingBack(): void
    {
        // One frame in the log, and a statement that ran before it, outside it: the frame's
        // rollback is not the statement's.
        $log = new StatementLog();
        $outside = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 2, 2 => 1], 1);
        $log->began();
        $inside = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 3, 2 => 1], 1);
        $log->rolledBack();

        self::assertNotNull($outside);
        self::assertNotNull($inside);
        self::assertSame([StatementLog::COMMITTED, StatementLog::VOID], [$log->fate($outside), $log->fate($inside)]);
    }

    public function testACountNotKnownIsLookedAfterAsADeleteThatTookRows(): void
    {
        // Watched, a DELETE whose count is past an int is looked at as one that took rows: not
        // known is not none.
        $log = new StatementLog();
        $log->watch(1, 'Crate::items', 'CrateItem', ['id'], ['2' => true], 'SELECT 1');

        self::assertNotSame([], $log->toObserveAfter('DELETE FROM CrateItem WHERE id = ?', [1 => 2], '9223372036854775808'));
        self::assertNotSame([], $log->toObserveAfter('DELETE FROM CrateItem WHERE id = ?', [1 => 2], null), 'nor is no count');
        self::assertSame([], $log->toObserveAfter('DELETE FROM CrateItem WHERE id = ?', [1 => 2], '0'), 'and none is none');
    }

    public function testAJoinRowWrittenWithACountNotKnownMakesItsTargetOneToLookAt(): void
    {
        // A target about to go, not held when the flush began: a join row written for it since
        // is a reason to look -- with a count not known as with one.
        $log = new StatementLog();
        $log->watch(1, 'Article::tags', 'Tag', ['id'], [], 'SELECT 1', ['7' => true], ['table' => 'article_tag', 'columns' => ['tag_id']]);
        $log->executed('INSERT INTO article_tag (article_id, tag_id) VALUES (?, ?)', [1 => 3, 2 => 7], '9223372036854775808');

        self::assertNotSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 7], 1));
    }

    public function testTwoNestedFlushesOnTheSameSavepointNameAreTwoFrames(): void
    {
        // DBAL names a savepoint after its level, so two nested flushes one after the other
        // both open DOCTRINE_2. The first is released and the second rolled back: a name, or
        // a depth, would void the first one's statement with the second's.
        $log = new StatementLog();

        $mark1 = $log->mark();
        $log->began();
        $log->claim($mark1, 1);
        $outer = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [2, 1], 1);

        $mark2 = $log->mark();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->claim($mark2, 2);
        $released = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [5, 1], 1);
        $log->executed('RELEASE SAVEPOINT DOCTRINE_2', [], 0);

        $mark3 = $log->mark();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->claim($mark3, 3);
        $dead = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [7, 2], 1);
        $log->executed('ROLLBACK TO SAVEPOINT DOCTRINE_2', [], 0);

        $after = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [3, 2], 1);
        $log->committed();

        self::assertNotNull($outer);
        self::assertNotNull($released);
        self::assertNotNull($dead);
        self::assertNotNull($after);

        self::assertSame([StatementLog::COMMITTED, 1], [$log->fate($outer), $log->ownerOf($outer)]);
        self::assertSame([StatementLog::COMMITTED, 2], [$log->fate($released), $log->ownerOf($released)], 'the released one is the second flush\'s, and it committed');
        self::assertSame(StatementLog::VOID, $log->fate($dead), 'the rolled-back one did not happen');
        self::assertSame([StatementLog::COMMITTED, 1], [$log->fate($after), $log->ownerOf($after)], 'and what ran after the rollback is the outer flush\'s, not the dead one\'s');
    }

    public function testASecondRollbackToTheSameSavepointVoidsWhatRanAfterTheFirst(): void
    {
        // A savepoint is still there after a ROLLBACK TO it. An application that manages its
        // own rolls back to it twice, and what ran in between goes too -- modelled as a
        // release, it would have survived.
        $log = new StatementLog();

        $log->began();
        $log->executed('SAVEPOINT mine', [], 0);
        $first = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['a', 1], 1);
        $log->executed('ROLLBACK TO SAVEPOINT mine', [], 0);
        $between = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['b', 1], 1);
        $log->executed('ROLLBACK TO SAVEPOINT mine', [], 0);
        $last = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['c', 1], 1);
        $log->executed('RELEASE SAVEPOINT mine', [], 0);
        $log->committed();

        self::assertNotNull($first);
        self::assertNotNull($between);
        self::assertNotNull($last);
        self::assertSame(
            [StatementLog::VOID, StatementLog::VOID, StatementLog::COMMITTED],
            [$log->fate($first), $log->fate($between), $log->fate($last)],
        );
    }

    public function testTwoNestedFlushesDyingOneAfterTheOtherVoidOnlyTheirOwn(): void
    {
        // The savepoint a dead nested flush rolled back to is still open, so the next nested
        // flush's DOCTRINE_2 is opened INSIDE it, under the same name. Its ROLLBACK TO is
        // meant for the newer one: aimed at the older, it voids what the outer flush ran
        // between the two -- statements that commit.
        $log = new StatementLog();

        $mark1 = $log->mark();
        $log->began();
        $log->claim($mark1, 1);
        $mark2 = $log->mark();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->claim($mark2, 2);
        $firstDead = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [7, 2], 1);
        $log->executed('ROLLBACK TO SAVEPOINT DOCTRINE_2', [], 0);
        $between = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [2, 1], 1);

        $mark3 = $log->mark();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->claim($mark3, 3);
        $secondDead = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [9, 2], 1);
        $log->executed('ROLLBACK TO SAVEPOINT DOCTRINE_2', [], 0);
        $last = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [3, 1], 1);
        $log->committed();

        self::assertNotNull($firstDead);
        self::assertNotNull($between);
        self::assertNotNull($secondDead);
        self::assertNotNull($last);
        self::assertSame(
            [StatementLog::VOID, StatementLog::COMMITTED, StatementLog::VOID, StatementLog::COMMITTED],
            [$log->fate($firstDead), $log->fate($between), $log->fate($secondDead), $log->fate($last)],
        );
        self::assertSame([1, 1], [$log->ownerOf($between), $log->ownerOf($last)], 'both belong to the outer flush');
    }

    public function testAFrameAnotherListenerOpenedFirstIsNotTheFlushs(): void
    {
        // Between a flush's onFlush and the transaction Doctrine begins for it, a listener
        // behind this one opens and closes its own. The flush claims after its statement
        // ran, and what it claims is the last frame opened since it marked, directly inside
        // the one open then -- Doctrine's.
        $log = new StatementLog();

        $outer = $log->mark();
        $log->began();
        $log->claim($outer, 1);

        $mark = $log->mark();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $theirs = $log->executed('INSERT INTO audit_note (text) VALUES (?)', [1 => 'x'], 1);
        $log->executed('RELEASE SAVEPOINT DOCTRINE_2', [], 0);
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $ours = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 2, 2 => 1], 1);
        $log->claim($mark, 2);
        $log->executed('RELEASE SAVEPOINT DOCTRINE_2', [], 0);
        $log->committed();

        self::assertNotNull($theirs);
        self::assertNotNull($ours);
        self::assertSame([1, 2], [$log->ownerOf($theirs), $log->ownerOf($ours)]);
    }

    public function testAFlushThatNeverBeganLendsNothingToTheNextTransaction(): void
    {
        $log = new StatementLog();

        $outer = $log->mark();
        $log->began();
        $log->claim($outer, 1);

        $log->mark(); // flush 2 is refused before it begins, and never claims
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $application = $log->executed('UPDATE Crate SET status = ? WHERE code = ?', [1 => 'audited', 2 => 'C-1'], 1);
        $log->executed('RELEASE SAVEPOINT DOCTRINE_2', [], 0);
        $log->committed();

        self::assertNotNull($application);
        self::assertSame(1, $log->ownerOf($application), 'the application\'s transaction runs inside flush 1, and is not flush 2\'s');
    }

    public function testAFrameRolledBackToIsNotClaimedAfterwards(): void
    {
        // A flush that claims late -- its only claim is at postFlush -- must not take back a
        // frame its death already gave up.
        $log = new StatementLog();

        $outer = $log->mark();
        $log->began();
        $log->claim($outer, 1);

        $mark = $log->mark();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 7, 2 => 2], 1);
        $log->executed('ROLLBACK TO SAVEPOINT DOCTRINE_2', [], 0);
        $after = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [1 => 2, 2 => 1], 1);
        $log->claim($mark, 2);
        $log->committed();

        self::assertNotNull($after);
        self::assertSame(1, $log->ownerOf($after));
    }

    public function testAReleaseMakesNothingFinal(): void
    {
        $log = new StatementLog();

        $mark1 = $log->mark();
        $log->began();
        $log->claim($mark1, 1);
        $mark2 = $log->mark();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->claim($mark2, 2);
        $nested = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [5, 1], 1);
        $log->executed('RELEASE SAVEPOINT DOCTRINE_2', [], 0);

        self::assertNotNull($nested);
        self::assertSame(StatementLog::PENDING, $log->fate($nested), 'released into a transaction that has not committed');

        $log->rolledBack();

        self::assertSame(StatementLog::VOID, $log->fate($nested), 'and the outer rollback takes it');
    }

    public function testAFlushRefusedBeforeItOpenedAnythingOwnsNothing(): void
    {
        // A nested flush refused in onFlush said it was about to begin and never did: no
        // SAVEPOINT, no postFlush, no ROLLBACK TO. What the outer flush runs next -- its own
        // leftovers, and change sets the dead one computed -- is the outer flush's.
        $log = new StatementLog();

        $mark1 = $log->mark();
        $log->began();
        $log->claim($mark1, 1);
        $log->mark(); // flush 2 never begins
        $next = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [9, 2], 1);
        $log->committed();

        self::assertNotNull($next);
        self::assertSame([StatementLog::COMMITTED, 1], [$log->fate($next), $log->ownerOf($next)]);
    }

    public function testWhatTheNestedFlushRunsOnBehalfOfTheOuterOneIsTheNestedOnes(): void
    {
        // A nested flush carries out what the outer one scheduled and had not written. The
        // statement ran in the nested flush's frame, with the values the nested flush's
        // change set had merged into it, and that is whose it is.
        $log = new StatementLog();

        $mark1 = $log->mark();
        $log->began();
        $log->claim($mark1, 1);
        $mark2 = $log->mark();
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $log->claim($mark2, 2);
        $leftover = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [7, 2], 1);
        $log->executed('RELEASE SAVEPOINT DOCTRINE_2', [], 0);
        $log->committed();

        self::assertNotNull($leftover);
        self::assertSame(2, $log->ownerOf($leftover));
    }

    public function testWithoutSavepointsANestedRollbackIsSeenOnlyAsTheOuterOne(): void
    {
        // DBAL 3's default: a nested transaction opens nothing on the wire and its rollback
        // marks the transaction rollback-only. The outer commit is refused, and the one thing
        // the connection shows is the outermost ROLLBACK -- which is enough, because it takes
        // everything.
        $log = new StatementLog();

        $mark1 = $log->mark();
        $log->began();
        $log->claim($mark1, 1);
        $outer = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [2, 1], 1);
        $log->mark(); // flush 2 never begins
        $nested = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [7, 2], 1);
        $log->rolledBack();

        self::assertNotNull($outer);
        self::assertNotNull($nested);
        self::assertSame([StatementLog::VOID, StatementLog::VOID], [$log->fate($outer), $log->fate($nested)]);
    }

    public function testAStatementOutsideATransactionIsCommittedAsItRunsAndAFailedOneIsVoid(): void
    {
        $log = new StatementLog();

        $ran = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['a', 1], 1);
        $failed = $log->executed('UPDATE Article SET title = ? WHERE id = ?', [null, 1], null, failed: true);

        self::assertNotNull($ran);
        self::assertNotNull($failed);
        self::assertSame([StatementLog::COMMITTED, null], [$log->fate($ran), $log->ownerOf($ran)]);
        self::assertSame(StatementLog::VOID, $log->fate($failed));
        self::assertSame(['sql' => 'UPDATE Article SET title = ? WHERE id = ?', 'read' => 'UPDATE Article SET title = ? WHERE id = ?', 'params' => [null, 1], 'affected' => null, 'failed' => true, 'key' => null], $log->statement($failed));
    }

    public function testWhatIsLetGoOfDoesNotComeBackAndTheLogDoesNotGrow(): void
    {
        // A batch import in one transaction is hundreds of flushes and hundreds of thousands
        // of statements before one COMMIT. What a flush's statements say is read when it
        // publishes; after that the log lets go of them, and what stays is the frames that
        // are still open.
        $log = new StatementLog();
        $log->began();

        for ($flush = 1; $flush <= 500; ++$flush) {
            $mark = $log->mark();
            $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
            $log->claim($mark, $flush);

            for ($i = 0; $i < 20; ++$i) {
                $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [$i, $flush], 1);
            }

            $log->executed('RELEASE SAVEPOINT DOCTRINE_2', [], 0);
            $log->forgetUpTo($log->position());

            self::assertLessThanOrEqual(1, $log->size(), 'after flush '.$flush.' only the outer transaction is held');
        }

        $read = $log->position();
        $log->committed();
        $log->forgetUpTo($read);

        self::assertSame(0, $log->size());
        self::assertNull($log->statement($read));
    }

    public function testAFrameStillOpenIsKeptWhenItsStatementsAreLetGo(): void
    {
        // Letting go of what was read must not take the frame a later statement will run in:
        // its fate and its owner are read off that frame.
        $log = new StatementLog();

        $mark1 = $log->mark();
        $log->began();
        $log->claim($mark1, 1);
        $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['a', 1], 1);
        $log->forgetUpTo($log->position());

        $later = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['b', 1], 1);
        $log->committed();

        self::assertNotNull($later);
        self::assertSame([StatementLog::COMMITTED, 1], [$log->fate($later), $log->ownerOf($later)]);
    }

    /**
     * A key the connection gave out is the INSERT's that ran right before the asking, and no other
     * statement's: asked twice, the first answer stands; asked after an UPDATE, a failed INSERT or a
     * statement the log does not keep, it is nobody's.
     */
    public function testAKeyHandedOutIsKeptBesideTheInsertItWasAskedAfterAndNowhereElse(): void
    {
        $log = new StatementLog();
        $log->keepingOnly(static fn (string $table): bool => $table === 'Article');

        $one = $log->executed('INSERT INTO Article (title) VALUES (?)', [1 => 'One'], 1);
        $log->keyHandedOut('7');
        $log->keyHandedOut('8');

        $update = $log->executed('UPDATE Article SET title = ? WHERE id = ?', [1 => 'x', 2 => 7], 1);
        $log->keyHandedOut('9');

        $failed = $log->executed('INSERT INTO Article (title) VALUES (?)', [1 => 'Two'], null, failed: true);
        $log->keyHandedOut('10');

        $log->executed('INSERT INTO Unwatched (title) VALUES (?)', [1 => 'Three'], 1);
        $log->keyHandedOut('11');
        $after = $log->executed('INSERT INTO Article (title) VALUES (?)', [1 => 'Four'], 1);

        self::assertNotNull($one);
        self::assertNotNull($update);
        self::assertNotNull($failed);
        self::assertNotNull($after);
        self::assertSame(
            ['7', null, null, null],
            [$log->statement($one)['key'] ?? null, $log->statement($update)['key'] ?? null, $log->statement($failed)['key'] ?? null, $log->statement($after)['key'] ?? null],
            'the first answer, beside its INSERT; nothing beside the others, and nothing carried to the next INSERT',
        );
    }
}
