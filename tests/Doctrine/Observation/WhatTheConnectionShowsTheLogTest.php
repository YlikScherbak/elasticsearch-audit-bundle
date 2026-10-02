<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\TestConnection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The adapters against a real connection: what reaches the log, and that nothing else changes.
 *
 * Run on SQLite here and on MySQL 8 and Postgres 16 under both DBAL majors in the database
 * job, because savepoints and affected-row counts are exactly what differs between them.
 */
final class WhatTheConnectionShowsTheLogTest extends TestCase
{
    private StatementLog $log;

    /** @var list<string> every statement the connection ran, as Doctrine's own logging middleware saw it */
    private array $wire = [];

    protected function setUp(): void
    {
        if (TestConnection::isSqlite() && !\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the Doctrine tests.');
        }

        $this->log = new StatementLog();
    }

    #[DataProvider('savepointModes')]
    public function testANestedRollbackVoidsItsOwnStatementAndLeavesTheOuterOnes(bool $savepoints): void
    {
        $connection = $this->observed($savepoints);
        $this->aRow($connection, 1);

        $connection->beginTransaction();
        $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [2, 1]);
        $outer = $this->log->position();

        $connection->beginTransaction();
        $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [7, 1]);
        $nested = $this->log->position();
        $connection->rollBack();

        if (!self::savepoints($connection)) {
            // DBAL 3 without savepoints: the nested rollback marks the transaction and the
            // outer commit is refused. The log sees the one ROLLBACK that follows.
            try {
                $connection->commit();
                self::fail('the premise: a rollback-only transaction is not committed');
            } catch (\Doctrine\DBAL\ConnectionException) {
                $connection->rollBack();
            }

            self::assertSame([StatementLog::VOID, StatementLog::VOID], [$this->log->fate($outer), $this->log->fate($nested)]);

            return;
        }

        $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [3, 1]);
        $after = $this->log->position();
        $connection->commit();

        self::assertSame(3, (int) $connection->fetchOne('SELECT quantity FROM probe_row WHERE id = 1'), 'the premise: what the rows hold');
        self::assertSame(
            [StatementLog::COMMITTED, StatementLog::VOID, StatementLog::COMMITTED],
            [$this->log->fate($outer), $this->log->fate($nested), $this->log->fate($after)],
        );
        self::assertSame(['sql' => 'UPDATE probe_row SET quantity = ? WHERE id = ?', 'params' => [1 => 7, 2 => 1], 'affected' => 1, 'failed' => false, 'key' => null], $this->log->statement($nested), 'the parameters as bound, and the count the driver gave');
    }

    #[DataProvider('savepointModes')]
    public function testTwoNestedTransactionsOnOneSavepointNameAreTwoFrames(bool $savepoints): void
    {
        $connection = $this->observed($savepoints);

        if (!self::savepoints($connection)) {
            self::markTestSkipped('Without savepoints a nested transaction opens nothing to tell apart.');
        }

        $this->aRow($connection, 1);

        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [5, 1]);
        $released = $this->log->position();
        $connection->commit();
        $connection->beginTransaction();
        $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [9, 1]);
        $dead = $this->log->position();
        $connection->rollBack();
        $connection->commit();

        self::assertSame(5, (int) $connection->fetchOne('SELECT quantity FROM probe_row WHERE id = 1'));
        self::assertSame([StatementLog::COMMITTED, StatementLog::VOID], [$this->log->fate($released), $this->log->fate($dead)]);
    }

    #[DataProvider('savepointModes')]
    public function testAFailingStatementIsRecordedAndItsExceptionLeavesUntouched(bool $savepoints): void
    {
        $connection = $this->observed($savepoints);
        $this->aRow($connection, 1);

        try {
            $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [null, 1]);
            self::fail('the premise: NOT NULL refuses it');
        } catch (\Doctrine\DBAL\Exception\NotNullConstraintViolationException $e) {
            // the same exception the application would have had without the observer
        }

        $failed = $this->log->statement($this->log->position());
        self::assertNotNull($failed);
        self::assertTrue($failed['failed']);
        self::assertSame(StatementLog::VOID, $this->log->fate($this->log->position()));
    }

    #[DataProvider('savepointModes')]
    public function testTheObserverChangesNothingTheApplicationSees(bool $savepoints): void
    {
        // Same answers and the same statements, with and without it: an UPDATE of a value
        // the row already holds counts as whatever the driver says -- zero on MySQL -- and
        // not one statement is added.
        $answers = [];
        $statements = [];

        foreach ([false, true] as $observed) {
            $this->wire = [];
            $connection = $observed ? $this->observed($savepoints) : $this->plain($savepoints);
            $this->aRow($connection, 1);
            $this->wire = [];

            $said = [];
            $said[] = $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [1, 1]);
            $said[] = $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [2, 1]);
            $said[] = $connection->executeStatement('UPDATE probe_row SET quantity = ? WHERE id = ?', [2, 99]);
            $said[] = $connection->executeStatement('DELETE FROM probe_row WHERE id = ?', [99]);
            $connection->beginTransaction();
            $connection->beginTransaction();
            $said[] = $connection->executeStatement('DELETE FROM probe_row WHERE id = ?', [1]);
            $said[] = $connection->commit();
            $said[] = $connection->commit();
            $said[] = $connection->fetchAllAssociative('SELECT id FROM probe_row');

            $answers[] = $said;
            $statements[] = $this->wire;
        }

        self::assertSame($answers[0], $answers[1], 'what the application is told');
        self::assertSame($statements[0], $statements[1], 'and what the connection is asked');
    }

    public function testParametersPassedToExecuteAreNumberedAsBoundOnesAre(): void
    {
        // DBAL 3 lets a driver statement be given its parameters in execute(), as a list
        // counted from zero. The log numbers placeholders from one, as bindValue() does.
        $connection = $this->observed(false);
        $driver = method_exists($connection, 'getWrappedConnection') ? $connection->getWrappedConnection() : null;

        if (!$driver instanceof \Doctrine\DBAL\Driver\Connection || (new \ReflectionMethod(\Doctrine\DBAL\Driver\Statement::class, 'execute'))->getNumberOfParameters() === 0) {
            self::markTestSkipped('Only DBAL 3 takes parameters in execute().');
        }

        $this->aRow($connection, 1);
        $driver->prepare('UPDATE probe_row SET quantity = ? WHERE id = ?')->execute([4, 1]);

        self::assertSame([1 => 4, 2 => 1], $this->log->statement($this->log->position())['params'] ?? null);
    }

    #[DataProvider('savepointModes')]
    public function testReadsAreNotHeld(bool $savepoints): void
    {
        $connection = $this->observed($savepoints);
        $this->aRow($connection, 1);
        $before = $this->log->position();

        for ($i = 0; $i < 50; ++$i) {
            $connection->fetchOne('SELECT quantity FROM probe_row WHERE id = ?', [1]);
        }

        self::assertSame($before, $this->log->position());
    }

    /**
     * The connection's default, and savepoints on -- which DoctrineBundle configures, and
     * which DBAL 4 has no way to turn off.
     *
     * @return iterable<string, array{bool}>
     */
    public static function savepointModes(): iterable
    {
        yield 'the connection\'s default' => [false];
        yield 'savepoints on' => [true];
    }

    private function observed(bool $savepoints): Connection
    {
        $config = new Configuration();
        $config->setMiddlewares([$this->listening(), new ObservingMiddleware($this->log)]);

        return $this->connect($config, $savepoints);
    }

    private function plain(bool $savepoints): Connection
    {
        $config = new Configuration();
        $config->setMiddlewares([$this->listening()]);

        return $this->connect($config, $savepoints);
    }

    private function connect(Configuration $config, bool $savepoints): Connection
    {
        $connection = DriverManager::getConnection(TestConnection::params(), $config);
        TestConnection::reset($connection);

        if ($savepoints && method_exists($connection, 'setNestTransactionsWithSavepoints')) {
            $connection->setNestTransactionsWithSavepoints(true);
        }

        return $connection;
    }

    private function listening(): LoggingMiddleware
    {
        $wire = &$this->wire;

        return new LoggingMiddleware(new class($wire) extends AbstractLogger {
            /** @param list<string> $wire */
            public function __construct(private array &$wire)
            {
            }

            /**
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->wire[] = (string) $message.' '.json_encode($context['sql'] ?? null).' '.json_encode($context['params'] ?? null);
            }
        });
    }

    private function aRow(Connection $connection, int $quantity): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS probe_row');
        $connection->executeStatement('CREATE TABLE probe_row (id INT NOT NULL PRIMARY KEY, quantity INT NOT NULL)');
        $connection->executeStatement('INSERT INTO probe_row (id, quantity) VALUES (1, ?)', [$quantity]);
    }

    private static function savepoints(Connection $connection): bool
    {
        return !method_exists($connection, 'getNestTransactionsWithSavepoints') || $connection->getNestTransactionsWithSavepoints();
    }
}
