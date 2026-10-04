<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * What a query-tagging middleware does -- sqlcommenter, a tracer: a comment on every statement,
 * before it, after it, or both. Transaction and savepoint statements DBAL runs through exec()
 * get one too.
 */
final class CommentingMiddleware implements Middleware
{
    public function __construct(private readonly string $before = '', private readonly string $after = '')
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $before = $this->before;
        $after = $this->after;

        return new class($driver, $before, $after) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $before, private readonly string $after)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                // DBAL 3's exec() returns an int, DBAL 4's an int or a string: one class for each,
                // declared only where it runs.
                if (\Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware::onDbal3()) {
                    return new class(parent::connect($params), $this->before, $this->after) extends AbstractConnectionMiddleware {
                        public function __construct(Connection $connection, private readonly string $before, private readonly string $after)
                        {
                            parent::__construct($connection);
                        }

                        public function prepare(string $sql): Statement
                        {
                            return parent::prepare($this->before.$sql.$this->after);
                        }

                        public function query(string $sql): Result
                        {
                            return parent::query($this->before.$sql.$this->after);
                        }

                        public function exec(string $sql): int
                        {
                            return (int) parent::exec($this->before.$sql.$this->after);
                        }
                    };
                }

                return new class(parent::connect($params), $this->before, $this->after) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly string $before, private readonly string $after)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return parent::prepare($this->before.$sql.$this->after);
                    }

                    public function query(string $sql): Result
                    {
                        return parent::query($this->before.$sql.$this->after);
                    }

                    public function exec(string $sql): int|string
                    {
                        return parent::exec($this->before.$sql.$this->after);
                    }
                };
            }
        };
    }
}
