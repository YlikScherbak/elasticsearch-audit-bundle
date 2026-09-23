<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation\Dbal4;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * The DBAL 4 side of {@see \Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware}.
 *
 * @internal
 */
final class ObservedConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly StatementLog $log)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new ObservedStatement(parent::prepare($sql), $sql, $this->log);
    }

    public function query(string $sql): Result
    {
        try {
            $result = parent::query($sql);
        } catch (\Throwable $e) {
            $this->log->executed($sql, [], null, failed: true);

            throw $e;
        }

        if (!StatementLog::onlyReads($sql)) {
            $this->log->executed($sql, [], $result->rowCount());
        }

        return $result;
    }

    public function exec(string $sql): int|string
    {
        try {
            $affected = parent::exec($sql);
        } catch (\Throwable $e) {
            $this->log->executed($sql, [], null, failed: true);

            throw $e;
        }

        $this->log->executed($sql, [], $affected);

        return $affected;
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->log->began();
    }

    public function commit(): void
    {
        parent::commit();
        $this->log->committed();
    }

    public function rollBack(): void
    {
        parent::rollBack();
        $this->log->rolledBack();
    }
}
