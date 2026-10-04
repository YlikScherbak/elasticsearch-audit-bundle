<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

/**
 * A statement as the log reads it: its comments taken out, each by a space, and nothing else
 * changed -- or why it cannot be read so.
 *
 * A query tagger -- sqlcommenter, a tracer -- puts a comment before or after every statement
 * the connection runs; read as written, none of them was a statement the log knew, and the
 * history had nothing to be written from. What is read is this copy. What ran, and what the
 * log keeps, is the statement as written.
 *
 * A comment is told from what only looks like one by the dialect's own rules: nothing inside
 * a string literal or a quoted name is a comment. Where those rules leave the statement in
 * doubt, it is not read: a comment the server executes or takes as a hint (`/*! … *\/`,
 * `/*+ … *\/`) -- read as a space, it would be another statement than the one that ran -- an
 * unterminated literal, quoted name or comment, and a backslash before a quote in a literal
 * whose end depends on a server mode nobody here knows.
 *
 * @internal
 */
final class ReadableSql
{
    private function __construct(
        /** The statement without its comments, or null where it cannot be read. */
        public readonly ?string $text,
        /** Why it cannot be, where it cannot. */
        public readonly ?string $unreadable,
    ) {
    }

    public static function of(string $sql, SqlDialect $dialect): self
    {
        // Most statements hold nothing that could begin a comment: the persisters' never do.
        if (strpbrk($sql, $dialect === SqlDialect::MySql ? '-/#' : '-/') === false) {
            return new self($sql, null);
        }

        $read = '';
        $length = \strlen($sql);

        for ($i = 0; $i < $length;) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($c === "'") {
                $end = self::endOfLiteral($sql, $i, $dialect);

                if (\is_string($end)) {
                    return new self(null, $end);
                }

                $read .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '"' || ($c === '`' && $dialect !== SqlDialect::PostgreSql) || ($c === '[' && ($dialect === SqlDialect::Sqlite || $dialect === SqlDialect::Other))) {
                $close = $c === '[' ? ']' : $c;
                $end = self::endOfQuoted($sql, $i, $close, $c === '"' && $dialect === SqlDialect::MySql);

                if (\is_string($end)) {
                    return new self(null, $end);
                }

                $read .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            // Not inside a name: PostgreSQL lets a name hold a $ past its first character.
            if ($c === '$' && $dialect === SqlDialect::PostgreSql && ($i === 0 || preg_match('/[A-Za-z0-9_\x80-\xff]/', $sql[$i - 1]) !== 1) && preg_match('/\G\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $sql, $m, 0, $i) === 1) {
                $close = strpos($sql, $m[0], $i + \strlen($m[0]));

                if ($close === false) {
                    return new self(null, 'a dollar-quoted string that does not end');
                }

                $end = $close + \strlen($m[0]);
                $read .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if (($c === '-' && $next === '-' && ($dialect !== SqlDialect::MySql || self::startsALineComment($sql[$i + 2] ?? ''))) || ($c === '#' && $dialect === SqlDialect::MySql)) {
                $eol = strcspn($sql, "\r\n", $i);
                $read .= ' ';
                $i += $eol;

                continue;
            }

            if ($c === '/' && $next === '*') {
                $kind = $sql[$i + 2] ?? '';

                if ($kind === '!' || $kind === '+') {
                    return new self(null, 'a comment the server reads: '.($kind === '!' ? 'executable' : 'a hint'));
                }

                $end = self::endOfBlockComment($sql, $i, $dialect === SqlDialect::PostgreSql);

                if ($end === null) {
                    return new self(null, 'a comment that does not end');
                }

                $read .= ' ';
                $i = $end;

                continue;
            }

            $read .= $c;
            ++$i;
        }

        return new self($read, null);
    }

    /**
     * Where a string literal opened at $at ends -- the position past its closing quote -- or
     * why that cannot be told.
     */
    private static function endOfLiteral(string $sql, int $at, SqlDialect $dialect): int|string
    {
        // PostgreSQL's E'…' takes backslash escapes; its other strings take none -- unless the
        // server turned standard_conforming_strings off, which nothing here can see.
        $escaped = $dialect === SqlDialect::PostgreSql
            && $at > 0
            && ($sql[$at - 1] === 'E' || $sql[$at - 1] === 'e')
            && ($at === 1 || preg_match('/[A-Za-z0-9_$]/', $sql[$at - 2]) !== 1);
        $length = \strlen($sql);

        for ($i = $at + 1; $i < $length; ++$i) {
            $c = $sql[$i];

            if ($c === '\\' && $escaped) {
                ++$i;

                continue;
            }

            if ($c === '\\' && ($sql[$i + 1] ?? '') === "'" && ($dialect === SqlDialect::MySql || $dialect === SqlDialect::PostgreSql)) {
                return 'a backslash before a quote in a string literal, whose end depends on the server\'s mode';
            }

            if ($c === "'") {
                if (($sql[$i + 1] ?? '') === "'") {
                    ++$i;

                    continue;
                }

                return $i + 1;
            }
        }

        return 'a string literal that does not end';
    }

    /**
     * Where a quoted name -- or, on MySQL, a string in double quotes -- opened at $at ends,
     * the closing character doubled inside it.
     */
    private static function endOfQuoted(string $sql, int $at, string $close, bool $mayBeAMySqlString): int|string
    {
        $length = \strlen($sql);

        for ($i = $at + 1; $i < $length; ++$i) {
            if ($mayBeAMySqlString && $sql[$i] === '\\' && ($sql[$i + 1] ?? '') === '"') {
                return 'a backslash before a quote in a double-quoted string, whose end depends on the server\'s mode';
            }

            if ($sql[$i] === $close) {
                if (($sql[$i + 1] ?? '') === $close) {
                    ++$i;

                    continue;
                }

                return $i + 1;
            }
        }

        return 'a quoted name that does not end';
    }

    /** Where a block comment opened at $at ends -- past its `*\/` -- nested on PostgreSQL. */
    private static function endOfBlockComment(string $sql, int $at, bool $nests): ?int
    {
        $depth = 0;
        $length = \strlen($sql);

        for ($i = $at; $i < $length - 1; ++$i) {
            if ($sql[$i] === '/' && $sql[$i + 1] === '*' && ($nests || $depth === 0)) {
                ++$depth;
                ++$i;
            } elseif ($sql[$i] === '*' && $sql[$i + 1] === '/') {
                --$depth;
                ++$i;

                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return null;
    }

    /** MySQL's `--` begins a comment only before a space, a control character or the end. */
    private static function startsALineComment(string $after): bool
    {
        return $after === '' || \ord($after) <= 32;
    }
}
