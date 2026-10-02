<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

/**
 * Whether a Doctrine queue writes on a given connection — asked of the queue itself, which is
 * the only thing that knows, through configureSchema(), whose whole contract is "add your table
 * if this is your connection". Nothing is written and nothing is created: the schema exists only
 * to be asked. AuditTransaction refuses a queue that does not, and audit:check reports one.
 *
 * Where the answer is read is the one thing that differs between versions, and reading it in one
 * place was learned the hard way. Symfony 6.4 declares the method void and fills in the schema it
 * is given. Symfony 7 returns a schema; and on DBAL 4.5, whose schemas are edited into new ones,
 * the schema it returns is the only one with the table — the one handed in stays empty. Read off
 * the schema handed in, as 1.1 to 1.2.4 did, every queue on DBAL 4.5 looked like one on another
 * connection, and every audit transaction was refused.
 *
 * @internal
 */
final class WhereTheQueueWrites
{
    public static function isOn(DoctrineTransport $queue, Connection $connection): bool
    {
        $handedIn = new Schema();

        // On Symfony 6.4 the method is void and this is null; phpstan-dbal.php lets the analyser
        // run against 6.4 read it.
        $returned = $queue->configureSchema($handedIn, $connection, static fn (): bool => false);

        return ($returned instanceof Schema ? $returned : $handedIn)->getTables() !== [];
    }
}
