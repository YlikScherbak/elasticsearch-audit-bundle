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

    /** @var array<int, array{sql: string, read: string|null, params: array<array-key, mixed>, affected: int|null, failed: bool, frame: int, void: bool, owner?: int, observed?: array<string, list<list<mixed>>|null>, key?: int|string}> by sequence number */
    private array $statements = [];

    /** @var array<int, array{parent: int|null, label: int|null, savepoint: string|null, committed: bool, dead: bool}> by identity, in the order they were opened */
    private array $frames = [];

    /** @var list<int> the frames open now, outermost first */
    private array $open = [];

    private int $sequence = 0;

    private int $nextFrame = 0;

    /** How many statements a rollback has voided, ever: what a reader that keeps its place asks whether it can. */
    private int $voided = 0;

    /**
     * How many times the driver committed, and rolled back, an outermost transaction: what a
     * boundary that opened one asks of the operation it ran - whether it ended that one and
     * began another, which leaves the level where it was.
     */
    private int $commits = 0;
    private int $rollbacks = 0;

    /** How many of those, and of the transactions begun, were SQL sent round DBAL rather than asked of it. */
    private int $textual = 0;

    /** Whether an observer was put in front of a driver: what audit:check asks of the audited connection. */
    private bool $watching = false;

    /**
     * Which tables' statements are kept, once the listener has said ({@see keepingOnly()}); until
     * then, every statement's.
     *
     * @var (\Closure(string): bool)|null
     */
    private ?\Closure $keeps = null;

    /**
     * Keeps from here on the statements of the tables the closure says, and the connection's
     * transactions and savepoints always -- the statements that cannot be read too, since those
     * may be of any table. The listener says which tables a history is about: the watched classes',
     * the join tables of the links it watches, and their targets'.
     *
     * @param \Closure(string): bool $tables
     */
    public function keepingOnly(\Closure $tables): void
    {
        $this->keeps = $tables;
    }

    /**
     * The entry of the last statement not kept, which the next one of its frame shares.
     *
     * @var array{sql: string, read: string|null, params: array<array-key, mixed>, affected: int|null, failed: bool, frame: int, void: bool}|null
     */
    private ?array $unkept = null;

    /** @var array<string, array<string, array{label: string, flush: int, columns: list<string>, keys: array<string, true>, going: array<string, true>, linkedBy: array{table: string, columns: list<string>}|null, read: string}>> by table */
    private array $watches = [];

    /** How the connection's statements are written: the rules their comments are taken out by ({@see ReadableSql}). */
    private SqlDialect $dialect = SqlDialect::Other;

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

    /** The connection's dialect, told when it connects ({@see ObservingMiddleware::dialectOf()}). */
    public function speaks(SqlDialect $dialect): void
    {
        $this->dialect = $dialect;
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
        ++$this->commits;

        foreach ($this->open as $frame) {
            if (isset($this->frames[$frame])) {
                $this->frames[$frame]['committed'] = true;
            }
        }

        $this->open = [];
    }

    /** The outermost transaction rolled back: nothing in it happened. */
    public function rolledBack(): void
    {
        ++$this->rollbacks;

        if ($this->open !== []) {
            $this->voidFrom($this->open[0]);
        }

        $this->open = [];
    }

    /**
     * The session the open transactions ran in is over: its connection was closed because a
     * rollback failed, and the next use of it opens a new one.
     *
     * Not a rollback this log saw, and not recorded as one by anything that reads it: the
     * database discards what an unfinished transaction held when its connection drops, but
     * nothing here watched that happen. What it means for the log is narrower and certain -
     * nothing that session left unfinished is used again. Its statements are void, so no
     * history is built from them and no later commit can make them final; and no frame is
     * left open, so the next flush is not read as one nested inside a transaction that will
     * never end.
     */
    public function sessionEnded(): void
    {
        if ($this->open !== []) {
            $this->voidFrom($this->open[0]);
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
        $affected = self::counted($affected);

        // What is read is the statement without its comments -- a query tagger's, before or
        // after it. What is kept is the statement as it ran: the copy is never its text.
        $read = ReadableSql::of($sql, $this->dialect)->text;

        if (preg_match('/^\s*SAVEPOINT\s+(\S+)\s*$/i', $read ?? '', $m) === 1) {
            $this->open($m[1]);

            return null;
        }

        if (preg_match('/^\s*RELEASE\s+SAVEPOINT\s+(\S+)\s*$/i', $read ?? '', $m) === 1) {
            $this->release($m[1]);

            return null;
        }

        if (preg_match('/^\s*ROLLBACK\s+TO\s+SAVEPOINT\s+(\S+)\s*$/i', $read ?? '', $m) === 1) {
            $this->rollBackTo($m[1]);

            return null;
        }

        // A transaction begun, committed or rolled back by its SQL rather than through DBAL:
        // the statement as a whole, so that a block that merely starts with BEGIN is not one.
        // DBAL itself never sends these - it asks the driver - so whoever did went round DBAL,
        // whose own count of levels no longer matches the database's. Applied to the frames
        // all the same, because the database did it, and counted apart for whoever has to
        // know that DBAL no longer can be asked. One that failed changed nothing.
        $control = $failed ? null : self::transactionControl($read);

        if ($control !== null) {
            ++$this->textual;

            match ($control) {
                'begin' => $this->began(),
                'commit' => $this->committed(),
                'rollback' => $this->rolledBack(),
            };

            return null;
        }

        // A statement of a table no history is about is not kept: an import of rows nobody
        // audits is no work of the listener's. One that cannot be read is kept -- it may be of
        // any table, and is doubt where it writes.
        //
        // What is not kept is its text. Its place is: that something ran there, in which frame,
        // and whether it stood, parts what ran before it from what ran after -- two tables of one
        // change are next to each other in the log, and a join row's DELETE is right before its
        // owner's only with nothing standing between. One entry, shared by every such statement
        // of a frame that runs back to back, until a rollback marks it.
        $keeps = $this->keeps;

        if ($keeps !== null) {
            $shape = $read === null ? null : StatementShape::read($read);

            // By its last part too: a table behind a schema may be a watched one, and its doubt is
            // the replay's to say.
            if ($shape !== null && !$keeps($shape->table) && !$keeps($shape->name->parts[\count($shape->name->parts) - 1][0])) {
                $frame = $this->open === [] ? -1 : $this->open[\count($this->open) - 1];

                if ($this->unkept === null || $this->unkept['frame'] !== $frame || $this->unkept['failed'] !== $failed) {
                    $this->unkept = ['sql' => '', 'read' => '', 'params' => [], 'affected' => null, 'failed' => $failed, 'frame' => $frame, 'void' => false];
                }

                $this->statements[++$this->sequence] = $this->unkept;

                return null;
            }
        }

        // A count not known may be rows written.
        if ($read !== null && !$failed && $affected !== 0) {
            $this->aJoinRowWritten($read, $params);
        }

        $this->statements[++$this->sequence] = [
            'sql' => $sql,
            'read' => $read,
            'params' => $params,
            'affected' => $affected,
            'failed' => $failed,
            'frame' => $this->open === [] ? -1 : $this->open[\count($this->open) - 1],
            'void' => false,
        ];

        return $this->sequence;
    }

    /**
     * The count a statement reports, as this log keeps it: DBAL's is int|numeric-string, a string
     * where a driver's count is past what an int holds. The int, or null -- not known -- for one
     * past it, for none given, for anything not a count; read by its digits and never through a
     * float, so that it is not cut to PHP_INT_MAX. Not known is never nought: what reads a count
     * takes such a statement as written, and the replay says it is doubt.
     */
    private static function counted(int|string|null $affected): ?int
    {
        if (!\is_string($affected)) {
            return $affected;
        }

        if (preg_match('/^\s*\+?0*([0-9]+)\s*$/', $affected, $m) !== 1) {
            return null;
        }

        $digits = $m[1];
        $most = (string) \PHP_INT_MAX;

        if (\strlen($digits) > \strlen($most) || (\strlen($digits) === \strlen($most) && strcmp($digits, $most) > 0)) {
            return null;
        }

        return (int) $digits;
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
        $read = ReadableSql::of($sql, $this->dialect)->text;

        if ($read === null || $this->watches === [] || self::counted($affected) === 0 || preg_match('/^\s*DELETE\b/i', $read) !== 1) {
            return [];
        }

        $shape = StatementShape::read($read);

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
     *
     * Told by the statement without its comments. One whose comments cannot be taken out
     * is told as written, and so a read only when nothing stands before its first word: a
     * comment the server executes, put before it, could be anything.
     */
    public function onlyReads(string $sql): bool
    {
        return preg_match('/^\s*(SELECT|SHOW|PRAGMA|EXPLAIN)\b/i', ReadableSql::of($sql, $this->dialect)->text ?? $sql) === 1;
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

    /**
     * The driver's commits and rollbacks of an outermost transaction so far, counted once it
     * had answered - a commit that threw is not one. A nested level never reaches the driver:
     * DBAL turns it into a savepoint statement, or into nothing at all without savepoints.
     *
     * `textual` counts the BEGIN, COMMIT and ROLLBACK sent as SQL text among them: each one
     * leaves DBAL's level out of step with the database.
     *
     * @return array{commits: int, rollbacks: int, textual: int}
     */
    public function transactionsEnded(): array
    {
        return ['commits' => $this->commits, 'rollbacks' => $this->rollbacks, 'textual' => $this->textual];
    }

    /**
     * BEGIN, COMMIT or ROLLBACK as a statement of its own, in the spellings the supported
     * databases take: START TRANSACTION, the WORK and TRANSACTION noise words, SQLite's
     * DEFERRED, IMMEDIATE and EXCLUSIVE, and END and ABORT, which PostgreSQL and SQLite read
     * as COMMIT and ROLLBACK.
     *
     * @return 'begin'|'commit'|'rollback'|null
     */
    private static function transactionControl(?string $read): ?string
    {
        if ($read === null) {
            return null;
        }

        return match (true) {
            preg_match('/^\s*(?:BEGIN|START\s+TRANSACTION)(?:\s+(?:WORK|TRANSACTION|DEFERRED|IMMEDIATE|EXCLUSIVE)){0,2}\s*;?\s*$/i', $read) === 1 => 'begin',
            preg_match('/^\s*(?:COMMIT|END)(?:\s+(?:WORK|TRANSACTION))?\s*;?\s*$/i', $read) === 1 => 'commit',
            preg_match('/^\s*(?:ROLLBACK|ABORT)(?:\s+(?:WORK|TRANSACTION))?\s*;?\s*$/i', $read) === 1 => 'rollback',
            default => null,
        };
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
     * @return array{sql: string, read: string|null, params: array<array-key, mixed>, affected: int|null, failed: bool, key: int|string|null}|null
     */
    public function statement(int $statement): ?array
    {
        $entry = $this->statements[$statement] ?? null;

        // One not kept has a place and a fate, and no text to read.
        return $entry === null || $entry['sql'] === '' ? null : [
            'sql' => $entry['sql'],
            'read' => $entry['read'],
            'params' => $entry['params'],
            'affected' => $entry['affected'],
            'failed' => $entry['failed'],
            'key' => $entry['key'] ?? null,
        ];
    }

    /**
     * The key the database handed out for the row the last statement wrote, as the connection
     * answered whoever asked for it. Doctrine asks, right after each INSERT whose key the database
     * gives, before anything else runs on the connection (WhereDoctrineAsksForAGeneratedKeyTest),
     * so the answer is kept beside that INSERT and nowhere else: the observer asks nothing itself.
     *
     * It belongs to that execution only. Taken back with it, it goes with it; asked again, the
     * first answer stands; asked after anything but an INSERT that ran — a statement not kept, one
     * that failed, a savepoint's — it is no INSERT's, and is kept nowhere. A key the replay finds
     * missing is doubt, never the key of another execution.
     */
    public function keyHandedOut(int|string $key): void
    {
        $last = $this->statements[$this->sequence] ?? null;

        if ($last === null || $last['failed'] || isset($last['key']) || preg_match('/^\s*INSERT\b/i', $last['read'] ?? '') !== 1) {
            return;
        }

        $this->statements[$this->sequence]['key'] = $key;
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
            'committed' => false,
            'dead' => false,
        ];
        $this->open[] = $this->nextFrame;
    }

    private function release(string $savepoint): void
    {
        $at = $this->innermostOpen($savepoint);

        if ($at === null) {
            return; // a savepoint this log never saw opened: nothing to hand on
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
        $this->voidFrom($frame);

        // Everything opened inside it is gone; it stays open itself, because the savepoint is
        // still there and a second ROLLBACK TO it voids what runs after the first.
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

    /** Voids every statement executed in this frame, or in a frame inside it. */
    private function voidFrom(int $frame): void
    {
        foreach ($this->statements as $sequence => $entry) {
            if (!$entry['void'] && $this->isInside($entry['frame'], $frame)) {
                $this->statements[$sequence]['void'] = true;
                ++$this->voided;
            }
        }
    }

    private function isInside(int $frame, int $outer): bool
    {
        // A frame's chain of parents is never longer than the frames there are: walked that many
        // steps at most, so that no mutant of the walk can make it go on for ever -- one did, and
        // timed out on one machine where a test caught it on another.
        $f = $frame;

        foreach ($this->frames as $_) {
            if ($f === $outer) {
                return true;
            }

            // Above the outermost frame; -1, a statement outside every frame, has no entry and is
            // there one step later.
            if ($f === null) {
                return false;
            }

            $f = $this->frames[$f]['parent'] ?? null;
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
