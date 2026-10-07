<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Outbox;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * A driver whose rollback fails, when the test says so, without rolling anything back -
 * what a connection that is gone, or a server that refuses, looks like from above.
 */
final class RollBackFailsWhenAsked implements Middleware
{
    public static bool $fails = false;

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            /**
             * @param array<string, mixed> $params
             */
            public function connect(array $params): DriverConnection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function rollBack(): void
                    {
                        if (RollBackFailsWhenAsked::$fails) {
                            throw new \RuntimeException('the server did not answer the rollback');
                        }

                        parent::rollBack();
                    }
                };
            }
        };
    }
}
