<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * A driver, under the observer, whose count of each statement matching a pattern is past what an
 * int holds -- DBAL 4's numeric-string. DBAL 4 only: DBAL 3 counts in an int.
 */
final class CountsPastAnInt implements Middleware
{
    public const PAST = '9223372036854775808';

    public function __construct(private readonly string $pattern)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $pattern = $this->pattern;

        return new class($driver, $pattern) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $pattern)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $pattern = $this->pattern;

                return new class(parent::connect($params), $pattern) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly string $pattern)
                    {
                        parent::__construct($connection);
                    }

                    public function exec(string $sql): int|string
                    {
                        $affected = parent::exec($sql);

                        return preg_match($this->pattern, $sql) === 1 ? CountsPastAnInt::PAST : $affected;
                    }

                    public function prepare(string $sql): Statement
                    {
                        $statement = parent::prepare($sql);

                        if (preg_match($this->pattern, $sql) !== 1) {
                            return $statement;
                        }

                        return new class($statement) extends AbstractStatementMiddleware {
                            public function execute(): Result
                            {
                                return new class(parent::execute()) extends AbstractResultMiddleware {
                                    public function rowCount(): int|string
                                    {
                                        return CountsPastAnInt::PAST;
                                    }
                                };
                            }
                        };
                    }
                };
            }
        };
    }
}
