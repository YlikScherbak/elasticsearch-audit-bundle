<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Keys;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * A driver middleware of the tests' own, no bundle code in it: it writes down, in the order the
 * driver is asked, every statement executed and every lastInsertId() and what it answered.
 */
final class KeyWatchingMiddleware implements Middleware
{
    /** @var list<string> */
    public array $said = [];

    public function wrap(Driver $driver): Driver
    {
        $said = &$this->said;

        return new class($driver, $said) extends AbstractDriverMiddleware {
            /** @param list<string> $said */
            public function __construct(Driver $driver, private array &$said)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                $connection = parent::connect($params);

                return (string) (new \ReflectionMethod(DriverConnection::class, 'commit'))->getReturnType() !== 'void'
                    ? new Dbal3WatchedConnection($connection, $this->said)
                    : new Dbal4WatchedConnection($connection, $this->said);
            }
        };
    }

    /**
     * The statements and keys about one table, in the order they were asked: "INSERT" for an
     * INSERT into it, "key N" for each lastInsertId() and its answer.
     *
     * @return list<string>
     */
    public function about(string $table): array
    {
        $about = [];

        foreach ($this->said as $one) {
            if (str_starts_with($one, 'key ')) {
                $about[] = $one;
            } elseif (preg_match('/^INSERT INTO "?'.preg_quote($table, '/').'"?[ (]/i', $one) === 1) {
                $about[] = 'INSERT '.$table;
            } elseif (preg_match('/^INSERT INTO "?(\w+)"?[ (]/i', $one, $into) === 1) {
                $about[] = 'INSERT '.$into[1];
            }
        }

        return $about;
    }
}
