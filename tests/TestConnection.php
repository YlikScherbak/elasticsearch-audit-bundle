<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Which database the transactional tests run against.
 *
 * SQLite in memory by default, because that is what makes a test suite worth running
 * on a laptop — and a real server when `AUDIT_DB_URL` says so, because SQLite is
 * exactly where the things these tests are about do not behave the same:
 *
 * - **DDL commits the transaction it is standing in** on MySQL and does not on
 *   Postgres, which decides whether `auto_setup` on the outbox queue can quietly end
 *   the transaction the records were supposed to be committed by.
 * - **Messenger's Doctrine transport inserts through `RETURNING`** on Postgres and
 *   through `lastInsertId()` elsewhere, so "the insert opened its own transaction" is
 *   a different statement on each.
 * - **Savepoints** are what a nested `rollBack()` uses in DBAL 4 unconditionally; DBAL
 *   3 has a branch that marks the outer transaction rollback-only instead, and the
 *   whole outbox guarantee is written around which of those happens.
 *
 * None of that is visible against SQLite, which has no savepoint semantics worth the
 * name and commits DDL like everything else.
 */
final class TestConnection
{
    /**
     * @return array<string, mixed>
     */
    public static function params(): array
    {
        $url = getenv('AUDIT_DB_URL');

        if (!\is_string($url) || $url === '') {
            return ['driver' => 'pdo_sqlite', 'memory' => true];
        }

        // Parsed rather than passed as a "url" key: DBAL 4 removed that shortcut from
        // DriverManager, and the whole point of this matrix is that it runs on both.
        return (new DsnParser([
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'mysql' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
        ]))->parse($url);
    }

    public static function isSqlite(): bool
    {
        return !\is_string(getenv('AUDIT_DB_URL')) || getenv('AUDIT_DB_URL') === '';
    }

    /**
     * An empty database, whatever was left in it.
     *
     * A fresh in-memory SQLite needs nothing; a server keeps what the last test wrote,
     * and every one of these tests builds its own schema in setUp(). Dropping by name
     * rather than through SchemaTool because the outbox queue's table is not part of
     * any entity mapping and would survive.
     */
    public static function reset(Connection $connection): void
    {
        if (self::isSqlite()) {
            return;
        }

        $tables = $connection->createSchemaManager()->listTableNames();

        if ($tables === []) {
            return;
        }

        // Order would decide this otherwise, and there is no order that works: the
        // fixtures reference each other in both directions. Postgres takes CASCADE on
        // the drop; MySQL parses it and ignores it, so there the constraints come off
        // for the duration instead.
        $mysql = str_contains($connection->getDatabasePlatform()::class, 'MySQL');

        if ($mysql) {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }

        try {
            foreach ($tables as $table) {
                $quoted = $connection->quoteSingleIdentifier($table);

                $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s%s', $quoted, $mysql ? '' : ' CASCADE'));
            }
        } finally {
            if ($mysql) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }
}
