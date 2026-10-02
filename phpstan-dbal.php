<?php

declare(strict_types=1);

/*
 * The connection adapters are written twice, once per DBAL major, because the driver
 * interface differs in ways no one class can satisfy. Only the one matching the installed
 * DBAL can be analysed: the other overrides methods whose signatures are not there. It is
 * still scanned, so the class that chooses between them resolves both names. CI runs the
 * analysis against the oldest dependencies as well as the newest, which is where each of
 * the two is checked.
 */

use Doctrine\DBAL\Driver\Connection;

$other = (string) (new ReflectionMethod(Connection::class, 'commit'))->getReturnType() === 'void' ? 'Dbal3' : 'Dbal4';

/*
 * Symfony 6.4 declares DoctrineTransport::configureSchema() void, and fills in the schema it is
 * given; 7 returns a schema, which on DBAL 4.5 is the only one with the queue's table. The outbox
 * reads both (src/Outbox/WhereTheQueueWrites.php), so against 6.4 it reads the result of a void
 * method on purpose — there, and nowhere else.
 */
$ignoreErrors = [];

if (class_exists(Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport::class)
    && (string) (new ReflectionMethod(Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport::class, 'configureSchema'))->getReturnType() === 'void'
) {
    $ignoreErrors[] = ['identifier' => 'method.void', 'path' => __DIR__.'/src/Outbox/WhereTheQueueWrites.php'];
    $ignoreErrors[] = ['identifier' => 'instanceof.alwaysFalse', 'path' => __DIR__.'/src/Outbox/WhereTheQueueWrites.php'];
}

return [
    'parameters' => [
        'excludePaths' => [
            'analyse' => [__DIR__.'/src/Doctrine/Observation/'.$other],
        ],
        'ignoreErrors' => $ignoreErrors,
    ],
];
