<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Tells a {@see StatementLog} what the connection does, and changes nothing about it.
 *
 * An observer, and held to that: it runs no statement of its own, alters no parameter,
 * returns what the driver returned, and lets every exception through as it came. What it
 * does NOT do is as deliberate -- it leaves the connection's options alone. MySQL counts an
 * UPDATE that writes the value a row already holds as touching nothing, unless
 * FOUND_ROWS is set; setting it would change what executeStatement() returns to the whole
 * application, which is a bundle quietly rewriting somebody else's semantics. So a count of
 * zero is recorded as the driver said it and read as meaning nothing for an UPDATE.
 *
 * The two DBAL majors disagree on the driver interface in ways no one class can satisfy:
 * DBAL 3 returns a boolean from commit() and the wrapping connection passes it on to the
 * application, DBAL 4 returns nothing. So there are two thin adapters, chosen when the
 * connection is made, and everything they observe goes to the one log that holds the rules.
 * The same shape as Symfony's own Doctrine debug middleware, and for the same reason.
 */
final class ObservingMiddleware implements Middleware
{
    public function __construct(private readonly StatementLog $log)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->log) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly StatementLog $log)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                $connection = parent::connect($params);

                if (ObservingMiddleware::onDbal3()) {
                    return new Dbal3\ObservedConnection($connection, $this->log);
                }

                return new Dbal4\ObservedConnection($connection, $this->log);
            }
        };
    }

    /**
     * Whether the installed DBAL is the one that nests a transaction without a savepoint
     * unless told to.
     *
     * Asked of the driver interface rather than of an installed version: it is the
     * signature the adapter has to match that differs, and the same answer decides whether
     * a nested flush can be told apart on the wire.
     */
    public static function onDbal3(): bool
    {
        return (string) (new \ReflectionMethod(DriverConnection::class, 'commit'))->getReturnType() !== 'void';
    }
}
