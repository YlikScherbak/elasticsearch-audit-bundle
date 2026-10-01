<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementShape;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Lets go of the statements StatementShape has read before each test, so that every test reads
 * its own on a cold memo.
 *
 * The memo is the one piece of state in the bundle that outlives a test as it outlives a request:
 * StatementShape::read() keeps what it has read by the SQL alone — the same text always reads the
 * same — for the life of the process, bounded and the oldest let go first. That is right for a
 * worker and it was hiding tests from the mutation run. Coverage says a line is covered by the
 * tests that ran it, and a line of the parser ran only in the first test of the process to read
 * that statement: the next one got the shape from the memo. Infection picks the tests to run
 * against a mutant by that coverage, so the test that tells a mutant of the parser apart, if it
 * read the statement second, was never asked — four mutants of StatementShape.php line 163
 * escaped that testASchemaIsPartOfTheTablesName kills.
 *
 * The memo itself is not switched off: what it does within one test — the same shape read twice,
 * the oldest let go at the bound — is StatementShapeTest's to pin, and is still there.
 */
final class EveryTestReadsItsOwnStatements implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                (static function (): void {
                    self::$read = [];
                })->bindTo(null, StatementShape::class)();
            }
        });
    }
}
