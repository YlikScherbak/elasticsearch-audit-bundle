<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use PHPUnit\Framework\TestCase;

/**
 * The statement log's bookkeeping, asked directly: which frame a flush claims, what a rollback
 * voids and counts, who owns what ran with no frame of its own, what is let go of and what is
 * kept, and what a watch remembers when it is asked for twice.
 */
final class WhatTheLogKeepsOfFramesAndOwnersTest extends TestCase
{
    private const UPDATE = 'UPDATE Article SET title = ? WHERE id = ?';

    public function testAFlushClaimsTheFrameItOpenedAndNotOneOpenedBeforeItOrInsideIt(): void
    {
        // Another listener's transaction, opened and released after the mark; then the flush's
        // own savepoint, and a nested flush's inside it. The flush's is the last one opened
        // directly inside the frame open at the mark.
        $log = new StatementLog();
        $log->began();
        $mark = $log->mark();
        $log->executed('SAVEPOINT other', [], 0);
        $log->executed('RELEASE SAVEPOINT other', [], 0);
        $log->executed('SAVEPOINT flush', [], 0);
        $own = $log->executed(self::UPDATE, ['a', 1], 1);
        $log->executed('SAVEPOINT nested', [], 0);
        $nested = $log->executed(self::UPDATE, ['b', 1], 1);
        $log->executed('RELEASE SAVEPOINT nested', [], 0);
        $log->claim($mark, 7);

        self::assertNotNull($own);
        self::assertNotNull($nested);
        self::assertSame(7, $log->ownerOf($own));
        self::assertSame(7, $log->ownerOf($nested), 'an unlabelled frame inside lends its statements to the one around it');
    }

    public function testAFlushThatOpenedNoFrameClaimsNoneOfAnEarlierTransaction(): void
    {
        $log = new StatementLog();
        $log->began();
        $earlier = $log->executed(self::UPDATE, ['a', 1], 1);
        $log->committed();
        $log->claim($log->mark(), 7);

        self::assertNotNull($earlier);
        self::assertNull($log->ownerOf($earlier));
    }

    public function testAfterARollbackToASavepointWhatRunsNextIsInThatSavepointsFrame(): void
    {
        $log = new StatementLog();
        $log->began();
        $log->executed('SAVEPOINT outer', [], 0);
        $first = $log->executed(self::UPDATE, ['a', 1], 1);
        $log->executed('SAVEPOINT inner', [], 0);
        $log->executed(self::UPDATE, ['b', 1], 1);
        $log->executed('ROLLBACK TO SAVEPOINT outer', [], 0);
        $after = $log->executed(self::UPDATE, ['c', 1], 1);

        self::assertNotNull($first);
        self::assertNotNull($after);
        self::assertSame($log->frameOf($first), $log->frameOf($after), 'the inner savepoint went with the rollback');
    }

    public function testEveryStatementARollbackVoidsIsCountedOnce(): void
    {
        $log = new StatementLog();
        $log->began();
        $log->executed('SAVEPOINT mine', [], 0);
        $log->executed(self::UPDATE, ['a', 1], 1);
        $log->executed(self::UPDATE, ['b', 1], 1);
        $log->executed('ROLLBACK TO SAVEPOINT mine', [], 0);
        $log->executed('ROLLBACK TO SAVEPOINT mine', [], 0);

        self::assertSame(2, $log->voided(), 'two statements, voided by the first rollback, not again by the second');
    }

    public function testAFlushHasDoneSomethingOnlyByAStatementOfItsOwnThatStood(): void
    {
        $log = new StatementLog();
        $log->began();
        $mark = $log->mark();
        $log->executed('SAVEPOINT dying', [], 0);
        $log->claim($mark, 1);
        $log->executed(self::UPDATE, ['a', 1], 1);
        $log->executed('ROLLBACK TO SAVEPOINT dying', [], 0);
        $log->executed('RELEASE SAVEPOINT dying', [], 0);
        $mark = $log->mark();
        $log->executed('SAVEPOINT standing', [], 0);
        $log->claim($mark, 2);
        $log->executed(self::UPDATE, ['b', 1], 1);
        $log->executed('RELEASE SAVEPOINT standing', [], 0);

        self::assertSame([false, true, false], [$log->hasDoneAnythingFor(1), $log->hasDoneAnythingFor(2), $log->hasDoneAnythingFor(3)]);
    }

    public function testWhatRanWithNoFrameIsClaimedFromAfterTheStartUpToAndWithTheEnd(): void
    {
        $log = new StatementLog();
        $before = $log->executed(self::UPDATE, ['a', 1], 1);
        $first = $log->executed(self::UPDATE, ['b', 1], 1);
        $last = $log->executed(self::UPDATE, ['c', 1], 1);
        $past = $log->executed(self::UPDATE, ['d', 1], 1);
        self::assertNotNull($before);
        self::assertNotNull($first);
        self::assertNotNull($last);
        self::assertNotNull($past);

        $log->claimUnowned($before, $last, 5);
        $log->claimUnowned($before, $past, 6);

        self::assertSame([null, 5, 5, 6], [$log->ownerOf($before), $log->ownerOf($first), $log->ownerOf($last), $log->ownerOf($past)], 'and what is owned already keeps its owner');
    }

    public function testLettingGoKeepsWhatComesAfterWithItsFrameAndItsFlushStarts(): void
    {
        $log = new StatementLog();
        $log->began();
        $mark = $log->mark();
        $log->executed('SAVEPOINT flush', [], 0);
        $log->claim($mark, 3);
        $early = $log->executed(self::UPDATE, ['a', 1], 1);
        $log->aFlushStarts();
        $late = $log->executed(self::UPDATE, ['b', 1], 1);
        $log->aFlushStarts();
        self::assertNotNull($early);
        self::assertNotNull($late);

        $log->forgetUpTo($early);

        self::assertNull($log->statement($early));
        self::assertSame(3, $log->ownerOf($late), 'its frame is kept, and its label');
        self::assertFalse($log->aFlushStartedAfter($early), 'what started after a statement let go of is let go of');
        self::assertTrue($log->aFlushStartedAfter($late), 'what started after one kept is kept');
    }

    public function testLettingGoTakesEveryFlushStartUpToTheStatement(): void
    {
        $log = new StatementLog();
        $first = $log->executed(self::UPDATE, ['a', 1], 1);
        $log->aFlushStarts();
        $second = $log->executed(self::UPDATE, ['b', 1], 1);
        $log->aFlushStarts();
        $log->executed(self::UPDATE, ['c', 1], 1);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $log->forgetUpTo($second);

        self::assertSame([false, false], [$log->aFlushStartedAfter($first), $log->aFlushStartedAfter($second)]);
    }

    public function testLettingGoKeepsTheFrameOfAStatementKeptAfterItsTransactionCommitted(): void
    {
        $log = new StatementLog();
        $log->began();
        $mark = $log->mark();
        $log->executed('SAVEPOINT flush', [], 0);
        $log->claim($mark, 3);
        $early = $log->executed(self::UPDATE, ['a', 1], 1);
        $late = $log->executed(self::UPDATE, ['b', 1], 1);
        $log->executed('RELEASE SAVEPOINT flush', [], 0);
        $log->committed();
        self::assertNotNull($early);
        self::assertNotNull($late);

        $log->forgetUpTo($early);

        self::assertSame(3, $log->ownerOf($late));
        self::assertSame(StatementLog::COMMITTED, $log->fate($late));
    }

    public function testAStatementTheLogDoesNotHoldHasNoOwner(): void
    {
        self::assertNull((new StatementLog())->ownerOf(99));
    }

    public function testASavepointOpenedOutsideATransactionCanBeRolledBackTo(): void
    {
        // SQLite begins a transaction for a SAVEPOINT run outside one; an application may.
        $log = new StatementLog();
        $log->executed('SAVEPOINT mine', [], 0);
        $dead = $log->executed(self::UPDATE, ['a', 1], 1);
        $log->executed('ROLLBACK TO SAVEPOINT mine', [], 0);

        self::assertNotNull($dead);
        self::assertSame(StatementLog::VOID, $log->fate($dead));
    }

    public function testAJoinRowInsertThatWroteNothingIsNoLinkToWatch(): void
    {
        // INSERT ... ON CONFLICT DO NOTHING, or INSERT IGNORE: no row, no link.
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], [], 'SELECT 1', ['9' => true], ['table' => 'article_tag', 'columns' => ['tag_id']]);
        $log->executed('INSERT INTO article_tag (article_id, tag_id) VALUES (?, ?)', [1 => 1, 2 => 9], 0);

        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 9], 1));
    }

    public function testADeleteThatTookNoRowAsTheDriverCountsItIsNotLookedAfter(): void
    {
        // A driver may count in a string.
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], ['5' => true], 'SELECT 1');

        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], '0'));
    }

    public function testWhatIsHeldIsCountedWhole(): void
    {
        $log = new StatementLog();
        $log->began();
        $log->executed('SAVEPOINT a', [], 0);
        $log->executed(self::UPDATE, ['a', 1], 1);
        $log->aFlushStarts();

        self::assertSame(1 + 2 + 1, $log->size(), 'a statement, two frames and a flush started');
    }

    public function testAWatchAskedForTwiceKeepsWhatWasGoingAndTakesTheNewJoinTable(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], [], 'SELECT 1', ['5' => true], ['table' => 'shelf_label', 'columns' => ['tag_id']]);
        $log->watch(1, 'tags', 'Tag', ['id'], [], 'SELECT 1', ['6' => true], ['table' => 'article_tag', 'columns' => ['tag_id']]);
        $log->executed('INSERT INTO article_tag (article_id, tag_id) VALUES (?, ?)', [1 => 1, 2 => 5], 1);

        self::assertCount(1, $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 1));
    }

    public function testEveryWatchOfATableIsAskedNotOnlyTheFirst(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'byCode', 'Tag', ['code'], ['x' => true], 'SELECT 1');
        $log->watch(1, 'byId', 'Tag', ['id'], ['5' => true], 'SELECT 2');

        self::assertSame(['byId'], array_column($log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 1), 'label'));
    }

    public function testAKeyOfTwoColumnsIsTheSameKeyInEitherOrder(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'lines', 'Line', ['a', 'b'], ['1|2' => true], 'SELECT 1');

        self::assertCount(1, $log->toObserveAfter('DELETE FROM Line WHERE b = ? AND a = ?', [1 => 2, 2 => 1], 1));
    }

    public function testAJoinRowWhoseInsertFailedIsNoLinkToWatch(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], [], 'SELECT 1', ['9' => true], ['table' => 'article_tag', 'columns' => ['tag_id']]);
        $log->executed('INSERT INTO article_tag (article_id, tag_id) VALUES (?, ?)', [1 => 1, 2 => 9], 1, failed: true);

        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 9], 1));
    }
}
