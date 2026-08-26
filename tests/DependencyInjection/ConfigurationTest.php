<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsAreSensible(): void
    {
        $config = $this->process(['client' => ['hosts' => ['http://localhost:9200']]]);

        self::assertSame('audit_log', $config['indices']['default']);
        self::assertSame([], $config['indices']['routing']);
        self::assertSame('keyword', $config['indices']['object_id_type']);
        self::assertSame('sync', $config['transport']);
        self::assertSame('log', $config['on_failure']);
        self::assertSame('system', $config['actor']['fallback']);
        self::assertTrue($config['client']['ssl_verification']);
        self::assertSame(['enabled' => true, 'skip_empty_updates' => true, 'connection' => 'default'], $config['doctrine']);
    }

    public function testEitherHostsOrServiceIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Set either client.hosts or client.service.');

        $this->process([]);
    }

    public function testAnExistingClientServiceIsEnough(): void
    {
        $config = $this->process(['client' => ['service' => 'app.es_client']]);

        self::assertSame('app.es_client', $config['client']['service']);
    }

    public function testRoutingIsKeyedByObjectType(): void
    {
        $config = $this->process([
            'client' => ['hosts' => ['http://es:9200']],
            'indices' => ['routing' => ['auth' => 'audit_auth', 'stock' => 'audit_stock']],
        ]);

        self::assertSame(['auth' => 'audit_auth', 'stock' => 'audit_stock'], $config['indices']['routing']);
    }

    public function testUnknownTransportIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['client' => ['hosts' => ['http://es:9200']], 'transport' => 'kafka']);
    }

    public function testUnknownFailurePolicyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['client' => ['hosts' => ['http://es:9200']], 'on_failure' => 'ignore']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
