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
 * **Two questions, and this answers them apart.** Ownership says whose moment and context a
 * fact carries, and a frame nobody lived to claim -- a nested flush that died before any of
 * its statements was announced -- is lent to the frame around it. That is right for whose it
 * is and wrong for whether it stands: the lent statement is still void. Whether a record
 * stands is the fate of the one execution it describes ({@see fate()}), found by the row it
 * wrote and by not being void yet when the record was taken, and never by who owns it. Folding
 * the two into one -- "the flush's statements" -- is how a record of a flush that committed
 * was dropped for a statement its dead nested flush ran on the same row.
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

    /** @var array<int, array{sql: string, params: array<array-key, mixed>, affected: int|string|null, failed: bool, frame: int, void: bool, owner?: int, observed?: array<string, list<list<mixed>>|null>}> by sequence number */
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

    /** @var array<string, array<string, array{label: string, flush: int, columns: list<string>, keys: array<string, true>, going: array<string, true>, linkedBy: array{table: string, columns: list<string>}|null, read: string}>> by table */
    private array $watches = [];

    /** @var array<int, true> the statements after which a flush began, by sequence number */
    private array $flushesStartedAfter = [];

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
     * A flush is about to begin: whatever runs from here is not of one piece with what ran
     * before.
     *
     * Without savepoints a flush nested inside another opens no frame, and its first statement
     * can sit right after the outer flush's last one -- of the same row, in the same frame:
     * what tells one change of a JOINED entity, written a table at a time, from two changes of
     * it is where a flush began. Kept for as long as the statement before it is, and not for as
     * long as the flush: a nested flush is over long before its statements are read.
     */
    public function aFlushStarts(): void
    {
        $this->flushesStartedAfter[$this->sequence] = true;
    }

    /** Whether a flush began between this statement and the next one. */
    public function aFlushStartedAfter(int $statement): bool
    {
        return isset($this->flushesStartedAfter[$statement]);
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

        if (!$failed && (int) $affected > 0) {
            $this->aJoinRowWritten($sql, $params);
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
     * What to look at right after a DELETE, before the application goes on: for a flush about to
     * remove rows others' join rows point at, which of those rows' holders still hold them once
     * the DELETE has run -- the only moment at which what the database took with it by a cascade
     * can be told from what anything later took. The listener says what (the table, the key's
     * columns, the keys worth looking at, and the question to ask); the connection says when.
     *
     * A watch is a flush's, and goes with it ({@see forgetTheWatchesOf()}); one that outlives
     * its flush does no harm -- a DELETE of the same key later is looked at and belongs to no
     * flush, which is what the listener then says of it. It is not a right to one look: every
     * DELETE that takes such a row gets its own, at its own position. A flush that watches again
     * -- a target a listener removes after onFlush -- adds to what it watched.
     *
     * Which keys are worth a look is not settled where the watch is set. A key is watched from
     * there -- every target about to go -- and looked at once something says a row may point at
     * it: that it was held when the flush began ($keys), or a join row written for it since, an
     * INSERT of the join table that ran ($going, $linkedBy) -- the flush's own, or a flush's
     * nested in it, or the application's. That says when to look, never what happened: what the
     * look sees is the fact, and a join row taken back before the DELETE leaves a look at nothing.
     *
     * @param list<string>                                         $columns  the key's columns, in the order a key is joined in
     * @param array<string, true>                                  $keys     the keys to look at, each its columns' values joined with '|'
     * @param string                                               $read     the question, with one placeholder per key column, in that order
     * @param array<string, true>                                  $going    every key the flush is about to take, $keys among them
     * @param array{table: string, columns: list<string>}|null $linkedBy the join table, and its columns that point at the key, in $columns' order
     */
    public function watch(int $flush, string $label, string $table, array $columns, array $keys, string $read, array $going = [], ?array $linkedBy = null): void
    {
        $going += $keys;

        if ($going === []) {
            return;
        }

        $name = $label."\0".$flush;
        $before = $this->watches[$table][$name] ?? null;

        $this->watches[$table][$name] = [
            'label' => $label,
            'flush' => $flush,
            'columns' => $columns,
            'keys' => ($before['keys'] ?? []) + $keys,
            'going' => ($before['going'] ?? []) + $going,
            'linkedBy' => $linkedBy ?? $before['linkedBy'] ?? null,
            'read' => $read,
        ];
    }

    public function forgetTheWatchesOf(int $flush): void
    {
        foreach ($this->watches as $table => $watches) {
            foreach ($watches as $name => $watch) {
                if ($watch['flush'] === $flush) {
                    unset($this->watches[$table][$name]);
                }
            }

            if ($this->watches[$table] === []) {
                unset($this->watches[$table]);
            }
        }
    }

    /**
     * What to ask right after a statement that ran: nothing, unless it is a DELETE that took a
     * row of a watched key -- one that took none took nothing with it.
     *
     * @param array<array-key, mixed> $params
     *
     * @return list<array{label: string, read: string, params: list<mixed>}>
     */
    public function toObserveAfter(string $sql, array $params, int|string|null $affected): array
    {
        if ($this->watches === [] || (int) $affected === 0 || preg_match('/^\s*DELETE\b/i', $sql) !== 1) {
            return [];
        }

        $shape = StatementShape::read($sql);

        if ($shape === null || !$shape->exact || !isset($this->watches[$shape->table])) {
            return [];
        }

        $asks = [];

        foreach ($this->watches[$shape->table] as $watch) {
            if (!self::sameColumns(array_keys($shape->where), $watch['columns'])) {
                continue;
            }

            $values = array_map(static fn (string $column): mixed => $params[$shape->where[$column]] ?? null, $watch['columns']);
            $key = implode('|', array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $values));

            if (isset($watch['keys'][$key])) {
                $asks[] = ['label' => $watch['label'], 'read' => $watch['read'], 'params' => $values];
            }
        }

        return $asks;
    }

    /**
     * What was seen right after a statement, under the label it was asked for: the rows the
     * question gave, or null where asking it failed -- not known, never nothing.
     *
     * @param list<list<mixed>>|null $rows
     */
    public function observed(int $statement, string $label, ?array $rows): void
    {
        if (isset($this->statements[$statement])) {
            $this->statements[$statement]['observed'][$label] = $rows;
        }
    }

    /**
     * What was seen right after a statement, by label; kept with the statement and forgotten
     * with it, so that it shares its fate -- a statement rolled back takes what was seen of it.
     *
     * @return array<string, list<list<mixed>>|null>
     */
    public function observationsOf(int $statement): array
    {
        return $this->statements[$statement]['observed'] ?? [];
    }

    /**
     * A join row an INSERT wrote, for a watched key about to go: that key is looked at when its
     * DELETE runs ({@see watch()}).
     *
     * @param array<array-key, mixed> $params
     */
    private function aJoinRowWritten(string $sql, array $params): void
    {
        if ($this->watches === [] || preg_match('/^\s*INSERT\b/i', $sql) !== 1) {
            return;
        }

        $shape = StatementShape::read($sql);

        if ($shape === null) {
            return;
        }

        foreach ($this->watches as $table => $watches) {
            foreach ($watches as $name => $watch) {
                if ($watch['linkedBy'] === null || strcasecmp($watch['linkedBy']['table'], $shape->table) !== 0) {
                    continue;
                }

                $values = [];

                foreach ($watch['linkedBy']['columns'] as $column) {
                    $at = $shape->assigned[$column] ?? null;

                    if ($at === null) {
                        continue 2;
                    }

                    $values[] = $params[$at] ?? null;
                }

                $key = implode('|', array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $values));

                if (isset($watch['going'][$key])) {
                    $watch['keys'][$key] = true;
                    $this->watches[$table][$name] = $watch;
                }
            }
        }
    }

    /** How many watches are held, for the tests that pin that they go with their flush. */
    public function watches(): int
    {
        return array_sum(array_map('count', $this->watches));
    }

    /**
     * @param list<string> $named
     * @param list<string> $columns
     */
    private static function sameColumns(array $named, array $columns): bool
    {
        sort($named);
        sort($columns);

        return $named === $columns;
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

        return $this->statements[$statement]['owner'] ?? null;
    }

    /**
     * Whether a statement this flush owns stayed done: what tells a flush that wrote something
     * from one refused before it could, whatever events it raised -- one that only emptied a
     * collection raises none.
     */
    public function hasDoneAnythingFor(int $flush): bool
    {
        foreach (array_keys($this->statements) as $statement) {
            if ($this->ownerOf($statement) === $flush && $this->fate($statement) !== self::VOID) {
                return true;
            }
        }

        return false;
    }

    /**
     * A flush owns what ran while it did and no frame says whose it is.
     *
     * A frame is how a flush is told apart, and a flush that opens none has nothing to claim:
     * one nested in the application's transaction without savepoints -- DBAL 3's default --
     * runs its beginTransaction() without a word on the wire. Called with the statements since
     * the flush last said a statement of its own had run: a flush nested inside it claimed its
     * own meanwhile, and a flush that died before running anything never calls this.
     */
    public function claimUnowned(int $after, int $upTo, int $flush): void
    {
        for ($statement = $after + 1; $statement <= $upTo; ++$statement) {
            if (isset($this->statements[$statement]) && $this->ownerOf($statement) === null) {
                $this->statements[$statement]['owner'] = $flush;
            }
        }
    }

    /**
     * The frame a statement ran in -- -1 outside every transaction -- or null for one the log
     * has let go of: what tells the statements of one change of an entity from a flush nested
     * right after them, which runs in a savepoint of its own.
     */
    public function frameOf(int $statement): ?int
    {
        return $this->statements[$statement]['frame'] ?? null;
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

        foreach (array_keys($this->flushesStartedAfter) as $sequence) {
            if ($sequence <= $statement) {
                unset($this->flushesStartedAfter[$sequence]);
            }
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
        return \count($this->statements) + \count($this->frames) + \count($this->flushesStartedAfter);
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
