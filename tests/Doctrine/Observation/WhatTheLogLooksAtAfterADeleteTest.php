<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use PHPUnit\Framework\TestCase;

/**
 * What the log is told to look at right after a DELETE, and what it keeps of what was seen
 * (5.3, the cascade): the listener's watches and the connection's moment, fed by hand.
 */
final class WhatTheLogLooksAtAfterADeleteTest extends TestCase
{
    private const READ = 'SELECT article_id FROM article_tag WHERE tag_id = ?';

    public function testADeleteOfAWatchedKeyThatTookARowIsLookedAtAndNothingElseIs(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], ['5' => true], self::READ);

        self::assertSame([['label' => 'tags', 'read' => self::READ, 'params' => [5]]], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 1));
        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 0), 'a DELETE that took no row took nothing with it');
        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 6], 1), 'a key no owner holds');
        self::assertSame([], $log->toObserveAfter('DELETE FROM Label WHERE id = ?', [1 => 5], 1), 'another table');
        self::assertSame([], $log->toObserveAfter('UPDATE Tag SET label = ? WHERE id = ?', [1 => 'x', 2 => 5], 1), 'not a DELETE');
        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ? AND label = ?', [1 => 5, 2 => 'x'], 1), 'not by the key alone');
        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id IN (?)', [1 => 5], 1), 'not a conjunction of column = ?');
        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ? OR id = ?', [1 => 6, 2 => 5], 1), 'the key\'s column alone, and still not one row');
        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ? AND label LIKE ?', [1 => 5, 2 => 'x%'], 1), 'the key, and a condition that is no key');
    }

    public function testTwoFlushesWatchingUnderOneLabelEachKeepTheirOwn(): void
    {
        // A flush nested in another watches the same collection for other keys: the outer
        // flush's watch is not written over, and goes only with the outer flush.
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], ['5' => true], self::READ);
        $log->watch(2, 'tags', 'Tag', ['id'], ['6' => true], self::READ);
        $log->forgetTheWatchesOf(2);

        self::assertCount(1, $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 1));
    }

    public function testAKeyOfSeveralColumnsIsJoinedInTheWatchsOrderWhateverTheStatementsIs(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'parts', 'Part', ['kind', 'code'], ['x|7' => true], 'SELECT holder FROM holder_part WHERE part_kind = ? AND part_code = ?');

        self::assertSame(['x', 7], $log->toObserveAfter('DELETE FROM Part WHERE code = ? AND kind = ?', [1 => 7, 2 => 'x'], 1)[0]['params'] ?? null);
    }

    public function testEveryDeleteGetsItsOwnLookAndAWatchGoesWithItsFlush(): void
    {
        // Not a right to one look: taken back and run again, the DELETE is looked at again. And
        // the watch goes with the flush that set it, while another flush's stays.
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], ['5' => true], self::READ);
        $log->watch(2, 'labels', 'Tag', ['id'], ['5' => true], 'SELECT shelf_id FROM shelf_label WHERE tag_id = ?');

        self::assertCount(2, $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 1));
        self::assertCount(2, $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 1));

        $log->forgetTheWatchesOf(1);
        self::assertSame(['labels'], array_column($log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 1), 'label'));
        self::assertSame(1, $log->watches());

        $log->forgetTheWatchesOf(2);
        self::assertSame(0, $log->watches());
        self::assertSame([], $log->toObserveAfter('DELETE FROM Tag WHERE id = ?', [1 => 5], 1));
    }

    public function testWhatWasSeenIsKeptWithItsStatementAndForgottenWithIt(): void
    {
        $log = new StatementLog();
        $log->began();
        $at = $log->executed('DELETE FROM Tag WHERE id = ?', [1 => 5], 1);
        self::assertIsInt($at);

        $log->observed($at, 'tags', [[1], [2]]);
        $log->observed($at, 'labels', null);
        $log->observed($at + 10, 'tags', [[3]]); // no such statement: nothing kept

        self::assertSame(['tags' => [[1], [2]], 'labels' => null], $log->observationsOf($at));
        self::assertSame([], $log->observationsOf($at + 10));

        $log->committed();
        $log->forgetUpTo($at);
        self::assertSame([], $log->observationsOf($at), 'forgotten with the statement');
    }

    public function testAWatchWithNoKeysIsNoWatch(): void
    {
        $log = new StatementLog();
        $log->watch(1, 'tags', 'Tag', ['id'], [], self::READ);

        self::assertSame(0, $log->watches());
    }
}
