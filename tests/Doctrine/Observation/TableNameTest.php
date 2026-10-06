<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\TableName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a table a statement names may be a mapped one, though not as the mapping spells it: by
 * its last part, an unquoted one in any case, a quoted one exactly. Grounds for a doubt only.
 */
final class TableNameTest extends TestCase
{
    /**
     * @return iterable<string, array{list<array{string, bool}>, string, bool}>
     */
    public static function names(): iterable
    {
        yield 'in lower case' => [[['article', false]], 'Article', true];
        yield 'in upper case' => [[['ARTICLE', false]], 'Article', true];
        yield 'behind a schema' => [[['public', false], ['Article', false]], 'Article', true];
        yield 'behind a schema, in lower case' => [[['main', false], ['article', false]], 'Article', true];
        yield 'against a mapped table with a schema' => [[['article', false]], 'audit.Article', true];
        yield 'quoted, as mapped' => [[['Article', true]], 'Article', true];
        yield 'quoted, in another case' => [[['article', true]], 'Article', false];
        yield 'one quoted name with a dot in it' => [[['main.Article', true]], 'Article', false];
        yield 'another table' => [[['Articles', false]], 'Article', false];
        yield 'another table, a prefix of it' => [[['Art', false]], 'Article', false];
    }

    /**
     * @param non-empty-list<array{string, bool}> $parts
     */
    #[DataProvider('names')]
    public function testANameMayBeAMappedTable(array $parts, string $mapped, bool $may): void
    {
        self::assertSame($may, (new TableName($parts))->mayBe($mapped));
    }

    public function testANameIsWrittenWithItsPartsJoined(): void
    {
        self::assertSame('main.Article', (new TableName([['main', false], ['Article', false]]))->written());
        self::assertSame('main.Article', (new TableName([['main.Article', true]]))->written(), 'joined as the mapping is matched, whatever the parts');
    }
}
