<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Borsche\ElasticsearchAuditBundle\ElasticsearchAuditBundle;
use PHPUnit\Framework\TestCase;

final class ElasticsearchAuditBundleTest extends TestCase
{
    public function testPathIsTheRepositoryRoot(): void
    {
        $bundle = new ElasticsearchAuditBundle();

        self::assertSame(\dirname(__DIR__), $bundle->getPath());
        self::assertFileExists($bundle->getPath().'/composer.json');
    }
}
