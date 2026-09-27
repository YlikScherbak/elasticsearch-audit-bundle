<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Psr\Log\LoggerInterface;

/**
 * What is said of a statement the log binds to a watched row and that no flush owns: one rule
 * for an entity's row and for an element's.
 *
 * It ran outside every flush this listener saw -- the application's own SQL, which the bundle
 * does not audit -- or while one was running and nothing claimed it, a hole in how flushes
 * mark what they run. Neither is a flush's history, and neither is a failure of the flush that
 * publishes: so it is said in the log, by name, rather than dropped in silence or raised
 * against somebody else's operation. What it did to the row still counts for what comes after
 * it: the replay has applied it.
 *
 * Named by class, fields and the columns of the key, and nothing a statement carried: a value
 * would be one more road out for what redaction keeps in.
 *
 * @internal the listener's readers of the log
 */
final class NobodysStatement
{
    /**
     * @param list<string> $key the columns of the row's key
     */
    public static function say(LoggerInterface $logger, bool $whileAFlushRan, string $what, string $class, array $key): void
    {
        $context = ['field' => $what, 'class' => $class, 'key' => implode(', ', $key)];

        if ($whileAFlushRan) {
            $logger->warning('A statement changed {field} of a {class} row, keyed by {key}, while a flush was running, and no flush claimed it, so it is not in the history. That is a hole in how the audit listener marks what a flush runs, and worth reporting.', $context);

            return;
        }

        $logger->warning('A statement changed {field} of a {class} row, keyed by {key}, outside every flush, so it is not in the history: SQL the application ran itself, which the bundle does not audit.', $context);
    }
}
