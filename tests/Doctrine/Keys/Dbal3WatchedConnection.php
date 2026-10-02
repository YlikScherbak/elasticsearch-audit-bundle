<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Keys;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** DBAL 3 half of KeyWatchingMiddleware. */
final class Dbal3WatchedConnection extends AbstractConnectionMiddleware
{
    /** @param list<string> $said */
    public function __construct(Connection $connection, private array &$said)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $said = &$this->said;

        return new class(parent::prepare($sql), $sql, $said) extends AbstractStatementMiddleware {
            /** @param list<string> $said */
            public function __construct(Statement $statement, private readonly string $sql, private array &$said)
            {
                parent::__construct($statement);
            }

            public function execute($params = null): Result
            {
                $result = parent::execute($params);
                $this->said[] = $this->sql;

                return $result;
            }
        };
    }

    public function exec(string $sql): int
    {
        $affected = parent::exec($sql);
        $this->said[] = $sql;

        return $affected;
    }

    public function lastInsertId($name = null)
    {
        $key = parent::lastInsertId($name);
        $this->said[] = 'key '.$key;

        return $key;
    }
}
