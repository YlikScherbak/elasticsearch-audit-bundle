<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;

final class IndexResolverTest extends TestCase
{
    public function testRoutesByObjectTypeAndFallsBackToDefault(): void
    {
        $resolver = new IndexResolver('audit_log', ['auth' => 'audit_auth', 'stock' => 'audit_stock']);

        self::assertSame('audit_auth', $resolver->resolve('auth'));
        self::assertSame('audit_log', $resolver->resolve('order'));
        self::assertSame('audit_log', $resolver->default());
    }

    public function testAllListsEveryDistinctIndexOnce(): void
    {
        $resolver = new IndexResolver('audit_log', ['auth' => 'audit_auth', 'login' => 'audit_auth', 'order' => 'audit_log']);

        self::assertSame(['audit_log', 'audit_auth'], $resolver->all());
    }

    public function testAllIsAListEvenWhenTheRepeatedIndexIsInTheMiddle(): void
    {
        // A duplicate anywhere but last leaves array_unique() with a gap in its keys,
        // and an array with gaps is not a list: it json_encodes as an object, and every
        // consumer of all() is declared against list<string>. The existing case above
        // happens to repeat the last one, where the gap falls off the end and the
        // renumbering is invisible.
        $resolver = new IndexResolver('audit_log', ['auth' => 'audit_auth', 'order' => 'audit_log', 'stock' => 'audit_stock']);

        self::assertSame(['audit_log', 'audit_auth', 'audit_stock'], $resolver->all(), 'the keys were left where array_unique() put them');
    }

    public function testDefaultCannotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IndexResolver('');
    }
}
