<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

/**
 * Which rules a statement's comments, string literals and quoted names follow
 * ({@see ReadableSql}). Told by the connection's platform; a platform none of these is read
 * by the rules every one of them shares.
 *
 * @internal
 */
enum SqlDialect
{
    /** `#` and `-- ` begin a comment; a string may hold a backslash escape, by the server's mode. */
    case MySql;

    /** Block comments nest; `$tag$ … $tag$` quotes; `E'…'` holds backslash escapes. */
    case PostgreSql;

    /** Names quoted in double quotes, backticks or brackets; no backslash escapes. */
    case Sqlite;

    /** Line and block comments, `''` inside a string, `""` inside a quoted name. */
    case Other;

    public static function ofPlatform(object $platform): self
    {
        // By name rather than by class: SQLite's platform is SqlitePlatform on DBAL 3 and
        // SQLitePlatform on DBAL 4, and PHP compares class names without regard to case.
        return match (true) {
            is_a($platform, 'Doctrine\DBAL\Platforms\AbstractMySQLPlatform') => self::MySql,
            is_a($platform, 'Doctrine\DBAL\Platforms\PostgreSQLPlatform') => self::PostgreSql,
            is_a($platform, 'Doctrine\DBAL\Platforms\SQLitePlatform') => self::Sqlite,
            default => self::Other,
        };
    }
}
