<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

/**
 * What the connection did, in the order it did it, and what became of each statement.
 *
 * The listener is told about a flush by Doctrine, and Doctrine's account and the database's
 * part company in every place this bundle has had defects: a change set emptied before the
 * listener reads it, a postUpdate for a statement that never ran, an onClear that looks the
 * same whether a flush died or the application cleared. The connection does not have that
 * problem. It sees the statement, whether it failed, how many rows it touched, and every
 * boundary of the transaction it ran in -- and that is all this records.
 *
 * **A tree of openings, not a depth.** Every BEGIN and every SAVEPOINT opens a frame with an
 * identity of its own. DBAL names a savepoint after its level, so two nested flushes one after
 * the other both open DOCTRINE_2; a depth, or a name, would mix their statements up. The
 * frame is what a ROLLBACK TO, a RELEASE, a COMMIT or a ROLLBACK is applied to, and a
 * statement's fate is the fate of the frames it sits in:
 *
 *   - ROLLBACK TO a savepoint voids everything executed since it was opened -- in it and in
 *     every frame opened inside it -- and leaves it open: the savepoint is still there, and
 *     what runs next runs in it, so a second ROLLBACK TO voids that too. It stops belonging
 *     to the flush that opened it, which is dead, and what runs next is owned by the frame
 *     around it.
 *   - RELEASE hands a frame's statements to the frame around it. It makes nothing final: the
 *     outer transaction can still roll back.
 *   - COMMIT of the outermost frame is the only thing that does. ROLLBACK of it voids all.
 *
 * Without savepoints -- DBAL 3's default -- a nested transaction opens nothing on the wire
 * and its rollback is not seen at all. The connection marks the transaction rollback-only and
 * refuses the outer commit, so what is seen is the outermost ROLLBACK, and that voids the lot.
 *
 * **Whose statement it is.** A flush marks where the log stands when it is about to begin --
 * {@see mark()} -- and, once one of its statements has run, claims the frame Doctrine opened
 * for it: the last one opened since the mark, directly inside the frame that was open then.
 * {@see claim()} is after the fact on purpose. A label handed ahead to "the next frame
 * opened" was taken by whatever opened first: a listener's own transaction in onFlush,
 * before Doctrine began the flush's, or -- when the flush was refused before it began -- the
 * application's next transaction, which inherited a dead flush's number. A flush that never
 * reaches a statement claims nothing, and what runs after it belongs to whatever frame is
 * open around it. So a frame, not a moment, decides ownership: the same statement run by a
 * nested flush on behalf of the outer one is the nested flush's, because that is the frame
 * it ran in.
 *
 * Nothing here parses SQL beyond recognising the three savepoint statements, and nothing here
 * knows about entities. It is an observer: it never runs a statement, and the only thing it
 * keeps longer than a statement's own frame is what its caller asks it to.
 */
final class StatementLog
{
    public const PENDING = 'pending';
    public const VOID = 'void';
    public const COMMITTED = 'committed';

    /** @var array<int, array{sql: string, params: array<array-key, mixed>, affected: int|string|null, failed: bool, frame: int, void: bool}> by sequence number */
    private array $statements = [];

    /** @var array<int, array{parent: int|null, label: int|null, savepoint: string|null, open: bool, committed: bool, dead: bool}> by identity, in the order they were opened */
    private array $frames = [];

    /** @var list<int> the frames open now, outermost first */
    private array $open = [];

    private int $sequence = 0;

    private int $nextFrame = 0;

    /** How many statements a rollback has voided, ever: what a reader that keeps its place asks whether it can. */
    private int $voided = 0;

    /** Whether an observer was put in front of a driver: what audit:check asks of the audited connection. */
    private bool $watching = false;

    /**
     * @param bool $letsGo whether what its reader has read is let go. The listener is the one
     *                     reader, and a log that kept everything would grow with every
     *                     statement a process ever ran; a second reader replaying the log on
     *                     its own -- as the tests do, to hold the listener to the truth -- needs
     *                     it all kept
     */
    public function __construct(private readonly bool $letsGo = true)
    {
    }

    /**
     * An observer was put in front of the driver of a connection this log hears.
     *
     * Told when DBAL builds the connection, which is before anything is run on it: a
     * connection the middleware never wrapped is one whose statements this log will never
     * see, and the history then has nothing to be read from.
     */
    public function watchesADriver(): void
    {
        $this->watching = true;
    }

    public function isWatching(): bool
    {
        return $this->watching;
    }

    /**
     * Where the log stands: the last frame opened so far, and the frame open now.
     *
     * Taken in onFlush, before Doctrine begins the flush's transaction, and handed back to
     * {@see claim()} once it has.
     *
     * @return array{0: int, 1: int|null}
     */
    public function mark(): array
    {
        return [$this->nextFrame, $this->open === [] ? null : $this->open[\count($this->open) - 1]];
    }

    /**
     * The flush that marked the log owns the frame Doctrine opened for it.
     *
     * That is the last frame opened since the mark directly inside the frame that was open
     * then: a transaction another listener opened and closed in onFlush came first, and a
     * flush nested inside this one opens its frames further in. Claiming again is nothing,
     * and a frame already rolled back to is not claimed -- it belonged to a flush that died.
     *
     * @param array{0: int, 1: int|null} $mark
     */
    public function claim(array $mark, int $flush): void
    {
        [$after, $enclosing] = $mark;

        for ($frame = $this->nextFrame; $frame > $after; --$frame) {
            if (!isset($this->frames[$frame]) || $this->frames[$frame]['parent'] !== $enclosing) {
                continue;
            }

            if ($this->frames[$frame]['label'] === null && !$this->frames[$frame]['dead']) {
                $this->frames[$frame]['label'] = $flush;
            }

            return;
        }
    }

    /** The outermost transaction began. */
    public function began(): void
    {
        $this->open(null);
    }

    /** The outermost transaction committed: what survived in it is final. */
    public function committed(): void
    {
        foreach ($this->open as $frame) {
            $this->close($frame, committed: true);
        }

        $this->open = [];
    }

    /** The outermost transaction rolled back: nothing in it happened. */
    public function rolledBack(): void
    {
        if ($this->open !== []) {
            $this->voidFrom($this->open[0], 0);
        }

        foreach ($this->open as $frame) {
            $this->close($frame);
        }

        $this->open = [];
    }

    /**
     * A statement ran -- or a savepoint statement, which is how DBAL nests.
     *
     * @param array<array-key, mixed> $params
     *
     * @return int|null the statement's sequence number, or null for a savepoint statement
     */
    public function executed(string $sql, array $params, int|string|null $affected, bool $failed = false): ?int
    {
        if (preg_match('/^\s*SAVEPOINT\s+(\S+)\s*$/i', $sql, $m) === 1) {
            $this->open($m[1]);

            return null;
        }

        if (preg_match('/^\s*RELEASE\s+SAVEPOINT\s+(\S+)\s*$/i', $sql, $m) === 1) {
            $this->release($m[1]);

            return null;
        }

        if (preg_match('/^\s*ROLLBACK\s+TO\s+SAVEPOINT\s+(\S+)\s*$/i', $sql, $m) === 1) {
            $this->rollBackTo($m[1]);

            return null;
        }

        $this->statements[++$this->sequence] = [
            'sql' => $sql,
            'params' => $params,
            'affected' => $affected,
            'failed' => $failed,
            'frame' => $this->open === [] ? -1 : $this->open[\count($this->open) - 1],
            'void' => false,
        ];

        return $this->sequence;
    }

    /**
     * Whether a statement only reads, and so is not worth holding.
     *
     * A read changes nothing a history could be about, and a batch import runs a great many
     * of them inside the transaction the log has to hold until it ends. A statement this
     * cannot tell about -- a CTE that writes starts with WITH -- is kept.
     */
    public static function onlyReads(string $sql): bool
    {
        return preg_match('/^\s*(SELECT|SHOW|PRAGMA|EXPLAIN)\b/i', $sql) === 1;
    }

    /**
     * Whether a transaction is open: until it is not, what ran in it may still be rolled back,
     * and nothing in it is final.
     */
    public function inTransaction(): bool
    {
        return $this->open !== [];
    }

    /**
     * How many statements a rollback has voided since the log began.
     *
     * A reader that replays the log and keeps its place -- so that reading what the rows hold
     * now does not replay everything again -- is right to carry on from there only while this
     * has not moved: a rollback reaches back into what it has already read.
     */
    public function voided(): int
    {
        return $this->voided;
    }

    /** Where the log has got to, for a caller that wants to know what ran after this point. */
    public function position(): int
    {
        return $this->sequence;
    }

    /**
     * What became of a statement: still pending, voided by a rollback, or committed.
     *
     * A statement run outside any transaction -- autocommit -- is committed as it runs. One
     * that failed is void: it changed nothing.
     */
    public function fate(int $statement): string
    {
        $entry = $this->statements[$statement] ?? null;

        if ($entry === null || $entry['void'] || $entry['failed']) {
            return self::VOID;
        }

        if ($entry['frame'] === -1) {
            return self::COMMITTED;
        }

        $outermost = $this->outermostOf($entry['frame']);

        return $this->frames[$outermost]['committed'] ? self::COMMITTED : self::PENDING;
    }

    /**
     * The flush a statement belongs to: the label of the innermost labelled frame it ran in.
     *
     * An unlabelled frame -- the application's own transaction, or a savepoint no flush said
     * it was opening -- lends its statements to the frame around it.
     */
    public function ownerOf(int $statement): ?int
    {
        $frame = $this->statements[$statement]['frame'] ?? -1;

        while ($frame !== -1 && $frame !== null) {
            if ($this->frames[$frame]['label'] !== null) {
                return $this->frames[$frame]['label'];
            }

            $frame = $this->frames[$frame]['parent'];
        }

        return null;
    }

    /**
     * @return array{sql: string, params: array<array-key, mixed>, affected: int|string|null, failed: bool}|null
     */
    public function statement(int $statement): ?array
    {
        $entry = $this->statements[$statement] ?? null;

        return $entry === null ? null : [
            'sql' => $entry['sql'],
            'params' => $entry['params'],
            'affected' => $entry['affected'],
            'failed' => $entry['failed'],
        ];
    }

    /**
     * Lets go of every statement up to and including this one.
     *
     * The caller says when it has read what it needed -- not the COMMIT, which comes before
     * the postFlush that reads it. A frame that holds no statements any more and is closed
     * goes with them.
     */
    public function forgetUpTo(int $statement): void
    {
        if (!$this->letsGo) {
            return;
        }

        foreach (array_keys($this->statements) as $sequence) {
            if ($sequence > $statement) {
                break;
            }

            unset($this->statements[$sequence]);
        }

        $used = [];

        foreach ($this->statements as $entry) {
            for ($frame = $entry['frame']; $frame !== -1 && $frame !== null; $frame = $this->frames[$frame]['parent']) {
                $used[$frame] = true;
            }
        }

        foreach ($this->open as $frame) {
            for ($f = $frame; $f !== null; $f = $this->frames[$f]['parent']) {
                $used[$f] = true;
            }
        }

        $this->frames = array_intersect_key($this->frames, $used);
    }

    /** How much is held, for the tests that pin that it does not grow. */
    public function size(): int
    {
        return \count($this->statements) + \count($this->frames);
    }

    private function open(?string $savepoint): void
    {
        $parent = $this->open === [] ? null : $this->open[\count($this->open) - 1];

        $this->frames[++$this->nextFrame] = [
            'parent' => $parent,
            'label' => null,
            'savepoint' => $savepoint,
            'open' => true,
            'committed' => false,
            'dead' => false,
        ];
        $this->open[] = $this->nextFrame;
    }

    private function close(int $frame, bool $committed = false): void
    {
        if (!isset($this->frames[$frame])) {
            return;
        }

        $this->frames[$frame]['open'] = false;
        $this->frames[$frame]['committed'] = $committed;
    }

    private function release(string $savepoint): void
    {
        $at = $this->innermostOpen($savepoint);

        if ($at === null) {
            return; // a savepoint this log never saw opened: nothing to hand on
        }

        foreach (\array_slice($this->open, $at) as $frame) {
            $this->close($frame);
        }

        $this->open = \array_slice($this->open, 0, $at);
    }

    private function rollBackTo(string $savepoint): void
    {
        $at = $this->innermostOpen($savepoint);

        if ($at === null) {
            return;
        }

        $frame = $this->open[$at];
        $this->voidFrom($frame, 0);

        // Everything opened inside it is gone; it stays open itself, because the savepoint is
        // still there and a second ROLLBACK TO it voids what runs after the first.
        foreach (\array_slice($this->open, $at + 1) as $inner) {
            $this->close($inner);
        }

        $this->open = \array_slice($this->open, 0, $at + 1);

        // But it no longer belongs to the flush that opened it. DBAL rolls a nested
        // transaction back to its savepoint and lowers its level: what runs next is the
        // enclosing flush's, which is carrying on -- and left under the dead flush's number,
        // the outer flush's committed statements would have gone with it.
        if (isset($this->frames[$frame])) {
            $this->frames[$frame]['label'] = null;
            $this->frames[$frame]['dead'] = true;
        }
    }

    /** Voids every statement executed in this frame, or in a frame inside it, after a sequence number. */
    private function voidFrom(int $frame, int $after): void
    {
        foreach ($this->statements as $sequence => $entry) {
            if ($sequence > $after && !$entry['void'] && $this->isInside($entry['frame'], $frame)) {
                $this->statements[$sequence]['void'] = true;
                ++$this->voided;
            }
        }
    }

    private function isInside(int $frame, int $outer): bool
    {
        for ($f = $frame; $f !== -1 && $f !== null; $f = $this->frames[$f]['parent'] ?? null) {
            if ($f === $outer) {
                return true;
            }
        }

        return false;
    }

    /** The position in the open stack of the innermost frame opened as this savepoint. */
    private function innermostOpen(string $savepoint): ?int
    {
        for ($i = \count($this->open) - 1; $i >= 0; --$i) {
            if ($this->frames[$this->open[$i]]['savepoint'] === $savepoint) {
                return $i;
            }
        }

        return null;
    }

    private function outermostOf(int $frame): int
    {
        while (($parent = $this->frames[$frame]['parent']) !== null) {
            $frame = $parent;
        }

        return $frame;
    }
}
