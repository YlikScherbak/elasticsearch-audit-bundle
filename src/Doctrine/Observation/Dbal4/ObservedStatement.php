<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation\Dbal4;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\LookRightAfter;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * @internal
 */
final class ObservedStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $params = [];

    public function __construct(Statement $statement, private readonly string $sql, private readonly StatementLog $log, private readonly Connection $inner)
    {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->params[$param] = $value;
        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        try {
            $result = parent::execute();
        } catch (\Throwable $e) {
            $this->log->executed($this->sql, $this->params, null, failed: true);

            throw $e;
        }

        if (!StatementLog::onlyReads($this->sql)) {
            LookRightAfter::aStatement($this->log, $this->inner, $this->log->executed($this->sql, $this->params, $result->rowCount()), $this->sql, $this->params, $result->rowCount());
        }

        return $result;
    }
}
