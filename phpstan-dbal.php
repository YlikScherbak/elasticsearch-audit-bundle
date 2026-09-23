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

return [
    'parameters' => [
        'excludePaths' => [
            'analyse' => [__DIR__.'/src/Doctrine/Observation/'.$other],
        ],
    ],
];
