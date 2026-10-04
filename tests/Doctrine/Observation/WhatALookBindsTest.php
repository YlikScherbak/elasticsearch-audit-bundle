<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;

/**
 * The look right after a DELETE asks with the values the DELETE took: each bound as what it is.
 * A key that is no number is asked as the text it is, not as the number nothing of it makes.
 */
final class WhatALookBindsTest extends DoctrineTestCase
{
    public function testAKeyOfTextIsAskedAsText(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE TABLE look_code (code VARCHAR(20) NOT NULL PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE look_holder_code (holder_id INT NOT NULL, code VARCHAR(20) NOT NULL)');
        $connection->executeStatement("INSERT INTO look_code (code) VALUES ('abc'), ('0')");
        // No foreign key: the join row stays, and the look sees who still holds it.
        $connection->executeStatement("INSERT INTO look_holder_code (holder_id, code) VALUES (7, 'abc'), (8, '0')");

        $this->statements->watch(0, 'look_codes', 'look_code', ['code'], ['abc' => true], 'SELECT holder_id FROM look_holder_code WHERE code = ?');
        $connection->executeStatement('DELETE FROM look_code WHERE code = ?', ['abc']);

        self::assertSame(['look_codes' => [[7]]], array_map(
            static fn (?array $rows): ?array => $rows === null ? null : array_map(static fn (array $row): array => array_map('intval', $row), $rows),
            $this->statements->observationsOf($this->statements->position()),
        ));
    }
}
