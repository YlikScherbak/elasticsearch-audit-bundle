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
    public function testTwoNestedFlushesOnTheSameSavepointNameAreTwoFrames(): void
    {
        // DBAL names a savepoint after its level, so two nested flushes one after the other
        // both open DOCTRINE_2. The first is released and the second rolled back: a name, or
        // a depth, would void the first one's statement with the second's.
        $log = new StatementLog();

        $log->label(1);
        $log->began();
        $outer = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [2, 1], 1);

        $log->label(2);
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $released = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [5, 1], 1);
        $log->executed('RELEASE SAVEPOINT DOCTRINE_2', [], 0);

        $log->label(3);
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
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

        $log->label(1);
        $log->began();
        $log->label(2);
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
        $firstDead = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [7, 2], 1);
        $log->executed('ROLLBACK TO SAVEPOINT DOCTRINE_2', [], 0);
        $between = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [2, 1], 1);

        $log->label(3);
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
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

    public function testAReleaseMakesNothingFinal(): void
    {
        $log = new StatementLog();

        $log->label(1);
        $log->began();
        $log->label(2);
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
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

        $log->label(1);
        $log->began();
        $log->label(2);
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

        $log->label(1);
        $log->began();
        $log->label(2);
        $log->executed('SAVEPOINT DOCTRINE_2', [], 0);
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

        $log->label(1);
        $log->began();
        $outer = $log->executed('UPDATE CrateItem SET quantity = ? WHERE id = ?', [2, 1], 1);
        $log->label(2);
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
        self::assertSame(['sql' => 'UPDATE Article SET title = ? WHERE id = ?', 'params' => [null, 1], 'affected' => null, 'failed' => true], $log->statement($failed));
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
            $log->label($flush);
            $log->executed('SAVEPOINT DOCTRINE_2', [], 0);

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

        $log->label(1);
        $log->began();
        $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['a', 1], 1);
        $log->forgetUpTo($log->position());

        $later = $log->executed('UPDATE Article SET title = ? WHERE id = ?', ['b', 1], 1);
        $log->committed();

        self::assertNotNull($later);
        self::assertSame([StatementLog::COMMITTED, 1], [$log->fate($later), $log->ownerOf($later)]);
    }
}
