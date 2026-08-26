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

    public function testDefaultCannotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IndexResolver('');
    }
}
