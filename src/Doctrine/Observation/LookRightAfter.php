<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The connection's side of looking right after a DELETE ({@see StatementLog::watch()}): the one
 * place the observer runs a statement of its own, and why it may.
 *
 * **When.** Right after the application's statement has run and been logged, before control
 * goes back to the application -- the only moment nothing else can have run in between, which
 * no event of the ORM's offers: Doctrine runs every DELETE of a flush before announcing the
 * first removal.
 *
 * **Through what.** The driver's connection underneath the observed one, so that what is
 * asked is not logged as the application's, and asking cannot set off another look.
 *
 * **What it may not do.** Change what the application sees. The application's statement has
 * already succeeded, and whatever asking costs is the bundle's: a question that fails is
 * nothing more than a look not taken -- null, which the history says as doubt -- and never an
 * exception the application meets. Inside a transaction it is asked inside a savepoint of its
 * own and rolled back to on failure, since on PostgreSQL a failed statement leaves the whole
 * transaction unable to go on.
 *
 * @internal
 */
final class LookRightAfter
{
    private const SAVEPOINT = 'borsche_audit_look';

    /**
     * @param array<array-key, mixed> $params
     */
    public static function aStatement(StatementLog $log, Connection $connection, ?int $at, string $sql, array $params, int|string|null $affected): void
    {
        if ($at === null) {
            return;
        }

        foreach ($log->toObserveAfter($sql, $params, $affected) as $ask) {
            $log->observed($at, $ask['label'], self::ask($connection, $log->inTransaction(), $ask['read'], $ask['params']));
        }
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<list<mixed>>|null
     */
    private static function ask(Connection $connection, bool $inATransaction, string $sql, array $params): ?array
    {
        try {
            if ($inATransaction) {
                $connection->exec('SAVEPOINT '.self::SAVEPOINT);
            }
        } catch (\Throwable) {
            return null;
        }

        try {
            $statement = $connection->prepare($sql);

            foreach ($params as $i => $value) {
                $statement->bindValue($i + 1, $value, \is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
            }

            $rows = array_values(array_map('array_values', $statement->execute()->fetchAllNumeric()));
        } catch (\Throwable) {
            self::giveBack($connection, $inATransaction);

            return null;
        }

        try {
            if ($inATransaction) {
                $connection->exec('RELEASE SAVEPOINT '.self::SAVEPOINT);
            }
        } catch (\Throwable) {
            return null;
        }

        return $rows;
    }

    /** What a failed question left: rolled back to where it began, inside a transaction. */
    private static function giveBack(Connection $connection, bool $inATransaction): void
    {
        if (!$inATransaction) {
            return;
        }

        try {
            $connection->exec('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
            $connection->exec('RELEASE SAVEPOINT '.self::SAVEPOINT);
        } catch (\Throwable) {
            // Nothing more can be done from here, and nothing of it is the application's.
        }
    }
}
