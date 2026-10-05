<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

/**
 * What a statement says it does, read from its text: which table, which columns, and which
 * bound parameter each placeholder is.
 *
 * Only the three shapes Doctrine's persisters write, which were measured on ORM 2.19 to 3.7,
 * DBAL 3 and 4, SQLite, MySQL and Postgres and are the same on all of them:
 *
 *     INSERT INTO t (a, b) VALUES (?, ?)
 *     UPDATE t SET a = ?, v = v + 1 WHERE id = ? AND v = ?
 *     DELETE FROM t WHERE a = ? AND b = ?
 *
 * Anything else is not read at all -- {@see read()} returns null -- because a statement read
 * wrongly is worse than one not read: "not bound" can be reported, a wrong key cannot be
 * noticed. The key is taken from the columns named in the text and never from where a
 * parameter happens to sit, since a versioned entity puts its version after its identifier.
 *
 * A WHERE that is anything other than a conjunction of `column = ?` is still read, and says
 * so: {@see $exact} is false -- an OR, an IN, a literal, an IS NULL. Being exact is about the
 * text only. `DELETE FROM t WHERE id = ? AND status = ?` is exact and still does not prove
 * the row with that id went, because the other condition may not have held; that is why
 * whoever binds a statement to a row asks for the WHERE to name the key's columns and
 * nothing else. What to make of any of this is the caller's; this only refuses to hide it.
 *
 * Nothing here knows which columns are an identifier. That comes from the mapping.
 */
final class StatementShape
{
    public const INSERT = 'insert';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    /**
     * @param array<string, int|null> $assigned the columns an INSERT or UPDATE writes, and the
     *                                          parameter each takes -- null where the value is
     *                                          an expression, as a version's `v + 1` is
     * @param array<string, int>      $where    the columns the WHERE compares to a parameter
     * @param bool                    $exact    whether the WHERE is nothing but those
     *                                          comparisons, joined by AND
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $table,
        public readonly array $assigned,
        public readonly array $where,
        public readonly bool $exact,
    ) {
    }

    /** How many statements' shapes are kept read: a flush writes a few forms, many times over. */
    public const REMEMBERED = 256;

    /**
     * The shapes last read, by their SQL -- the only thing a shape is read from, so a statement's
     * text is the whole key: no parameter, row, flush or fate goes into it. Bounded, the oldest
     * let go first: reading one again costs time and nothing else, and a worker that runs for a
     * week does not keep every statement it has seen.
     *
     * @var array<string, self|null>
     */
    private static array $read = [];

    /**
     * What a statement says it does, or null where it is not one of the three forms. The same SQL
     * always reads the same, and a flush of twenty thousand rows writes one INSERT twenty
     * thousand times: it is read once.
     */
    public static function read(string $sql): ?self
    {
        if (\array_key_exists($sql, self::$read)) {
            return self::$read[$sql];
        }

        if (\count(self::$read) >= self::REMEMBERED) {
            unset(self::$read[array_key_first(self::$read)]);
        }

        return self::$read[$sql] = self::readAgain($sql);
    }

    private static function readAgain(string $sql): ?self
    {
        $tokens = self::tokens($sql);

        if ($tokens === null || $tokens === []) {
            return null;
        }

        $reader = new class($tokens) {
            private int $at = 0;

            private int $placeholders = 0;

            /** @param list<array{0: string, 1: string}> $tokens */
            public function __construct(private readonly array $tokens)
            {
            }

            /** @phpstan-impure */
            public function keyword(string $word): bool
            {
                $token = $this->tokens[$this->at] ?? null;

                if ($token !== null && $token[0] === 'word' && strcasecmp($token[1], $word) === 0) {
                    ++$this->at;

                    return true;
                }

                return false;
            }

            /** @phpstan-impure */
            public function symbol(string $symbol): bool
            {
                $token = $this->tokens[$this->at] ?? null;

                if ($token !== null && $token[0] === 'symbol' && $token[1] === $symbol) {
                    ++$this->at;

                    return true;
                }

                return false;
            }

            /** @phpstan-impure */
            private function placeholder(): ?int
            {
                $token = $this->tokens[$this->at] ?? null;

                if ($token !== null && $token[0] === 'placeholder') {
                    ++$this->at;

                    return ++$this->placeholders;
                }

                return null;
            }

            /**
             * A name, quoted or not, with its schema if it has one: `audit.CrateItem` and
             * `CrateItem` are different tables, and the mapping says which is meant.
             *
             * @phpstan-impure
             */
            public function name(): ?string
            {
                $name = $this->identifier();

                while ($name !== null && $this->symbol('.')) {
                    $part = $this->identifier();

                    if ($part === null) {
                        return null;
                    }

                    $name .= '.'.$part;
                }

                return $name;
            }

            /** @phpstan-impure */
            public function identifier(): ?string
            {
                $token = $this->tokens[$this->at] ?? null;

                if ($token !== null && ($token[0] === 'quoted' || ($token[0] === 'word' && !self::reserved($token[1])))) {
                    ++$this->at;

                    return $token[1];
                }

                return null;
            }

            /**
             * Skips one value up to a comma, a closing parenthesis or a keyword at its own
             * depth, counting the placeholders on the way; says whether it was exactly one.
             *
             * @phpstan-impure
             */
            public function value(): ?int
            {
                $single = $this->placeholder();

                if ($single !== null && $this->endsHere()) {
                    return $single;
                }

                $depth = 0;

                while (($token = $this->tokens[$this->at] ?? null) !== null) {
                    if ($depth === 0 && $this->endsHere()) {
                        break;
                    }

                    if ($token[0] === 'symbol' && $token[1] === '(') {
                        ++$depth;
                    } elseif ($token[0] === 'symbol' && $token[1] === ')') {
                        --$depth;
                    } elseif ($token[0] === 'placeholder') {
                        ++$this->placeholders;
                    }

                    ++$this->at;
                }

                return null;
            }

            public function done(): bool
            {
                return $this->at === \count($this->tokens);
            }

            private function endsHere(): bool
            {
                $token = $this->tokens[$this->at] ?? null;

                return $token === null
                    || ($token[0] === 'symbol' && ($token[1] === ',' || $token[1] === ')'))
                    || ($token[0] === 'word' && \in_array(strtoupper($token[1]), ['WHERE', 'AND', 'OR'], true));
            }

            private static function reserved(string $word): bool
            {
                return \in_array(strtoupper($word), ['INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'NULL', 'IS', 'IN'], true);
            }

            /**
             * The WHERE, as far as it is a conjunction of `column = ?`; anything else in it
             * makes it inexact, and a WHERE that cannot be followed to its end is not read.
             *
             * @return array{0: array<string, int>, 1: bool}|null
             *
             * @phpstan-impure
             */
            public function where(): ?array
            {
                $where = [];
                $exact = true;

                while (true) {
                    $column = $this->name();

                    if ($column !== null && $this->symbol('=') && ($parameter = $this->placeholder()) !== null && $this->atTheEndOfACondition()) {
                        $where[$column] = $parameter;
                    } else {
                        // Something other than `column = ?`: skipped to the next AND or OR
                        // at this depth with its placeholders counted, and not exact.
                        $exact = false;
                        $this->value();
                    }

                    if ($this->keyword('AND')) {
                        continue;
                    }

                    if ($this->keyword('OR')) {
                        $exact = false;

                        continue;
                    }

                    break;
                }

                return $this->done() ? [$where, $exact] : null;
            }

            private function atTheEndOfACondition(): bool
            {
                $token = $this->tokens[$this->at] ?? null;

                return $token === null || ($token[0] === 'word' && \in_array(strtoupper($token[1]), ['AND', 'OR'], true));
            }
        };

        if ($reader->keyword('INSERT')) {
            if (!$reader->keyword('INTO') || ($table = $reader->name()) === null || !$reader->symbol('(')) {
                return null;
            }

            $columns = [];

            do {
                $column = $reader->identifier();

                if ($column === null) {
                    return null;
                }

                $columns[] = $column;
            } while ($reader->symbol(','));

            if (!$reader->symbol(')') || !$reader->keyword('VALUES') || !$reader->symbol('(')) {
                return null;
            }

            $assigned = [];

            foreach ($columns as $i => $name) {
                if ($i > 0 && !$reader->symbol(',')) {
                    return null;
                }

                $assigned[$name] = $reader->value();
            }

            // One row: a second VALUES tuple is not a shape the persisters write.
            return $reader->symbol(')') && $reader->done() ? new self(self::INSERT, $table, $assigned, [], true) : null;
        }

        if ($reader->keyword('UPDATE')) {
            if (($table = $reader->name()) === null || !$reader->keyword('SET')) {
                return null;
            }

            $assigned = [];

            do {
                $column = $reader->name();

                if ($column === null || !$reader->symbol('=')) {
                    return null;
                }

                $assigned[$column] = $reader->value();
            } while ($reader->symbol(','));

            if (!$reader->keyword('WHERE') || ($where = $reader->where()) === null) {
                return null;
            }

            return new self(self::UPDATE, $table, $assigned, $where[0], $where[1]);
        }

        if ($reader->keyword('DELETE')) {
            if (!$reader->keyword('FROM') || ($table = $reader->name()) === null || !$reader->keyword('WHERE') || ($where = $reader->where()) === null) {
                return null;
            }

            return new self(self::DELETE, $table, [], $where[0], $where[1]);
        }

        return null;
    }

    /**
     * The tables a statement this reader could not read may write -- by a word of a write of its
     * own (INSERT, UPDATE, DELETE, REPLACE, MERGE, TRUNCATE) outside every literal and quoted
     * name -- each as it is named (a quoted name unquoted, a schema kept), or null for one whose
     * name cannot be read; an empty list for a statement that writes nothing. For any form: one
     * behind a common table expression, MySQL's REPLACE and its modifiers, SQLite's INSERT OR
     * REPLACE, a MERGE.
     *
     * Read only to say that such a statement is doubt: nothing is made of what it did. A word is
     * told from a literal by the quotes every dialect shares -- '…' with '' inside it, E'…' with a
     * backslash, $tag$…$tag$, "…", `…`, […] -- and the statement is the one the comments have
     * been taken out of ({@see ReadableSql}). Not a write of its own: a lock a read takes (FOR
     * UPDATE, FOR NO KEY UPDATE), an INSERT's other arm (ON CONFLICT DO UPDATE, ON DUPLICATE KEY
     * UPDATE), a foreign key's action (ON DELETE, ON UPDATE), a MERGE's arms (THEN UPDATE, THEN
     * DELETE, THEN INSERT -- the MERGE names the table), and REPLACE(…), the function.
     *
     * @return list<string|null>
     */
    public static function writesItCannotRead(string $read): array
    {
        $words = [];
        $length = \strlen($read);

        for ($i = 0; $i < $length;) {
            $c = $read[$i];

            if (ctype_space($c)) {
                ++$i;

                continue;
            }

            if (($c === 'E' || $c === 'e') && ($read[$i + 1] ?? '') === "'" && ($i === 0 || !ctype_alnum($read[$i - 1]))) {
                $i = self::pastALiteral($read, $i + 1, backslash: true);

                continue;
            }

            if ($c === "'") {
                $i = self::pastALiteral($read, $i, backslash: false);

                continue;
            }

            if ($c === '"' || $c === '`' || $c === '[') {
                $end = strpos($read, $c === '[' ? ']' : $c, $i + 1);
                $words[] = ['quoted', substr($read, $i + 1, ($end === false ? $length : $end) - $i - 1)];
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $read, $m, 0, $i) === 1) {
                $end = strpos($read, $m[0], $i + \strlen($m[0]));
                $i = $end === false ? $length : $end + \strlen($m[0]);

                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $read, $m, 0, $i) === 1) {
                $words[] = ['word', $m[0]];
                $i += \strlen($m[0]);

                continue;
            }

            $words[] = ['symbol', $c];
            ++$i;
        }

        $tables = [];

        foreach ($words as $at => [$kind, $word]) {
            $keyword = $kind === 'word' ? strtoupper($word) : '';

            if (!isset(self::WRITES[$keyword])) {
                continue;
            }

            $before = ($words[$at - 1][0] ?? '') === 'word' ? strtoupper($words[$at - 1][1]) : '';
            $after = $words[$at + 1] ?? null;

            if (\in_array($before, self::WRITES[$keyword]['not after'], true) || ($keyword === 'REPLACE' && $after === ['symbol', '('])) {
                continue;
            }

            $next = $at + 1;

            // What may stand between the word and the table: a modifier, SQLite's OR and its one
            // word, INTO or FROM, ONLY, TABLE.
            while (($words[$next][0] ?? '') === 'word') {
                $passed = strtoupper($words[$next][1]);

                if ($passed === 'OR' && \in_array(strtoupper($words[$next + 1][1] ?? ''), ['REPLACE', 'IGNORE', 'ABORT', 'FAIL', 'ROLLBACK'], true) && ($keyword === 'INSERT' || $keyword === 'UPDATE')) {
                    $next += 2;

                    continue;
                }

                if (!\in_array($passed, self::WRITES[$keyword]['passed'], true)) {
                    break;
                }

                ++$next;
            }

            $tables[] = self::nameAt($words, $next);
        }

        return $tables;
    }

    /**
     * The words of a write of its own: what may stand between each and its table, and the words
     * after which it is no write of its own.
     */
    private const WRITES = [
        'INSERT' => ['passed' => ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE', 'INTO'], 'not after' => ['THEN']],
        'REPLACE' => ['passed' => ['LOW_PRIORITY', 'DELAYED', 'INTO'], 'not after' => ['OR']],
        'UPDATE' => ['passed' => ['LOW_PRIORITY', 'IGNORE', 'ONLY'], 'not after' => ['FOR', 'KEY', 'DO', 'ON', 'THEN']],
        'DELETE' => ['passed' => ['LOW_PRIORITY', 'QUICK', 'IGNORE', 'FROM', 'ONLY'], 'not after' => ['ON', 'THEN']],
        'MERGE' => ['passed' => ['INTO', 'ONLY'], 'not after' => []],
        'TRUNCATE' => ['passed' => ['TABLE', 'ONLY'], 'not after' => []],
    ];

    /** Where a literal that opens at $at ends, the position after its closing quote. */
    private static function pastALiteral(string $sql, int $at, bool $backslash): int
    {
        $length = \strlen($sql);

        for ($j = $at + 1; $j < $length; ++$j) {
            if ($backslash && $sql[$j] === '\\') {
                ++$j;

                continue;
            }

            if ($sql[$j] === "'") {
                if (($sql[$j + 1] ?? '') === "'") {
                    ++$j;

                    continue;
                }

                return $j + 1;
            }
        }

        return $length;
    }

    /**
     * The name that begins at a word: a word or a quoted name, a schema and a dot before it.
     *
     * @param list<array{0: string, 1: string}> $words
     */
    private static function nameAt(array $words, int $at): ?string
    {
        $parts = [];

        while (isset($words[$at]) && ($words[$at][0] === 'quoted' || ($words[$at][0] === 'word' && !self::isAWriteOrClause($words[$at][1])))) {
            $parts[] = $words[$at][1];

            if (($words[$at + 1] ?? null) !== ['symbol', '.']) {
                return implode('.', $parts);
            }

            $at += 2;
        }

        return null;
    }

    private static function isAWriteOrClause(string $word): bool
    {
        return \in_array(strtoupper($word), ['SELECT', 'SET', 'WHERE', 'VALUES', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'TRUNCATE', 'INTO', 'FROM', 'USING', 'RETURNING', 'ONLY', 'TABLE', 'OR'], true);
    }

    /**
     * The statement as words, quoted names, placeholders and symbols -- with string literals
     * swallowed, so a question mark inside one is not taken for a placeholder.
     *
     * @return list<array{0: string, 1: string}>|null null for anything this does not follow:
     *                                                 a named parameter, a comment, an
     *                                                 unterminated quote
     */
    private static function tokens(string $sql): ?array
    {
        $tokens = [];
        $length = \strlen($sql);

        for ($i = 0; $i < $length;) {
            $c = $sql[$i];

            if (ctype_space($c)) {
                ++$i;

                continue;
            }

            if ($c === '"' || $c === '`') {
                $end = strpos($sql, $c, $i + 1);

                if ($end === false) {
                    return null;
                }

                $tokens[] = ['quoted', substr($sql, $i + 1, $end - $i - 1)];
                $i = $end + 1;

                continue;
            }

            if ($c === "'") {
                // A literal, with '' as an escaped quote inside it.
                $j = $i + 1;

                for (; ; ++$j) {
                    if ($j >= $length) {
                        return null;
                    }

                    if ($sql[$j] === "'") {
                        if (($sql[$j + 1] ?? '') === "'") {
                            ++$j;

                            continue;
                        }

                        break;
                    }
                }

                $tokens[] = ['literal', ''];
                $i = $j + 1;

                continue;
            }

            if ($c === '?') {
                $tokens[] = ['placeholder', '?'];
                ++$i;

                continue;
            }

            if ($c === ':' || ($c === '-' && ($sql[$i + 1] ?? '') === '-') || ($c === '/' && ($sql[$i + 1] ?? '') === '*')) {
                return null; // named parameters and comments are not what the persisters write
            }

            if ((ctype_alpha($c) || $c === '_') && preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $m, 0, $i) === 1) {
                $tokens[] = ['word', $m[0]];
                $i += \strlen($m[0]);

                continue;
            }

            if (ctype_digit($c) && preg_match('/\G[0-9]+(\.[0-9]+)?/', $sql, $m, 0, $i) === 1) {
                $tokens[] = ['literal', $m[0]];
                $i += \strlen($m[0]);

                continue;
            }

            if (preg_match('/\G(<>|!=|<=|>=|[=<>(),.+\-*\/])/', $sql, $m, 0, $i) === 1) {
                $tokens[] = ['symbol', $m[0]];
                $i += \strlen($m[0]);

                continue;
            }

            return null;
        }

        return $tokens;
    }
}
