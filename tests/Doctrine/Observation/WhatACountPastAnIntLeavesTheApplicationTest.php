<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\Dbal4\ObservedConnection;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use PHPUnit\Framework\TestCase;

/**
 * A count past what an int holds -- DBAL 4's numeric-string -- reaches the application as the
 * driver gave it: what the log keeps of it, a count not known, is the log's alone.
 */
final class WhatACountPastAnIntLeavesTheApplicationTest extends TestCase
{
    private const PAST = '9223372036854775808';

    protected function setUp(): void
    {
        if (!str_contains((string) (new \ReflectionMethod(Connection::class, 'exec'))->getReturnType(), 'string')) {
            self::markTestSkipped('DBAL 3 counts in an int.');
        }
    }

    public function testAStatementRunAtOnceAnswersTheDriversCount(): void
    {
        $inner = self::createStub(Connection::class);
        $inner->method('exec')->willReturn(self::PAST);
        $log = new StatementLog();

        self::assertSame(self::PAST, (new ObservedConnection($inner, $log))->exec('DELETE FROM CrateItem WHERE id = 2'));
        $kept = $log->statement($log->position());
        self::assertNotNull($kept);
        self::assertNull($kept['affected'], 'and the log keeps it as not known');
    }

    public function testAPreparedStatementAnswersTheDriversCount(): void
    {
        $result = self::createStub(Result::class);
        $result->method('rowCount')->willReturn(self::PAST);
        $statement = self::createStub(Statement::class);
        $statement->method('execute')->willReturn($result);
        $inner = self::createStub(Connection::class);
        $inner->method('prepare')->willReturn($statement);
        $log = new StatementLog();

        $prepared = (new ObservedConnection($inner, $log))->prepare('DELETE FROM CrateItem WHERE id = ?');
        $prepared->bindValue(1, 2, \Doctrine\DBAL\ParameterType::INTEGER);

        self::assertSame(self::PAST, $prepared->execute()->rowCount());
    }
}
