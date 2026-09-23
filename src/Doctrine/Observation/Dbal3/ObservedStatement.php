<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation\Dbal3;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
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

    /** @var array<int|string, mixed> bound by reference, read when the statement runs */
    private array $references = [];

    public function __construct(Statement $statement, private readonly string $sql, private readonly StatementLog $log)
    {
        parent::__construct($statement);
    }

    /**
     * @param int|string $param
     * @param mixed      $value
     * @param int        $type
     *
     * @return bool
     */
    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        $this->params[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    /**
     * @param int|string $param
     * @param mixed      $variable
     * @param int        $type
     * @param int|null   $length
     *
     * @return bool
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null)
    {
        $this->references[$param] = &$variable;

        return parent::bindParam($param, $variable, $type, ...\array_slice(\func_get_args(), 3));
    }

    /**
     * @param array<int|string, mixed>|null $params
     */
    public function execute($params = null): Result
    {
        $bound = $params ?? $this->references + $this->params;

        try {
            $result = parent::execute($params);
        } catch (\Throwable $e) {
            $this->log->executed($this->sql, $bound, null, failed: true);

            throw $e;
        }

        if (!StatementLog::onlyReads($this->sql)) {
            $this->log->executed($this->sql, $bound, $result->rowCount());
        }

        return $result;
    }
}
