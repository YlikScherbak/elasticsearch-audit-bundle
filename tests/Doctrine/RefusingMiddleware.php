<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * A database refusing one statement -- a constraint the mapping does not know of, say: every
 * statement that begins as given fails when it runs, and nothing else does.
 */
final class RefusingMiddleware implements Middleware
{
    public function __construct(private readonly string $refused)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $refused = $this->refused;

        return new class($driver, $refused) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $refused)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->refused) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly string $refused)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $statement = parent::prepare($sql);

                        return !str_starts_with($sql, $this->refused) ? $statement : new class($statement) extends AbstractStatementMiddleware {
                            // DBAL 3's takes the parameters; DBAL 4's does not, and takes an optional one.
                            public function execute($params = null): Result
                            {
                                throw new \RuntimeException('Refused by the database.');
                            }
                        };
                    }
                };
            }
        };
    }
}
