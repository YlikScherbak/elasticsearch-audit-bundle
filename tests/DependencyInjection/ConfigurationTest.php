<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\DependencyInjection;

use Borsche\ElasticsearchAuditBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(['enabled' => true, 'object_types' => [], 'numeric_fields' => [], 'max_held' => 10000], $config['coalescing']);
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

    /**
     * @param array<string, mixed> $indices
     */
    #[DataProvider('badIndexNames')]
    public function testIndexNamesElasticsearchWouldRefuseAreRejected(array $indices, string $offender): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($offender);

        $this->process(['client' => ['hosts' => ['http://es:9200']], 'indices' => $indices]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badIndexNames(): iterable
    {
        yield 'uppercase' => [['default' => 'Audit_Log'], 'Audit_Log'];
        yield 'leading dash' => [['default' => '-audit'], '-audit'];
        yield 'leading underscore in routing' => [['routing' => ['auth' => '_audit_auth']], '_audit_auth'];
        yield 'wildcard' => [['routing' => ['auth' => 'audit_*']], 'audit_*'];
        yield 'space' => [['default' => 'audit log'], 'audit log'];
        yield 'dot only' => [['default' => '..'], '".."'];
    }

    public function testDotsDashesAndDigitsAreFineInIndexNames(): void
    {
        $config = $this->process(['client' => ['hosts' => ['http://es:9200']], 'indices' => ['default' => 'crm.audit-log-2026', 'routing' => ['auth' => 'crm.audit-auth']]]);

        self::assertSame('crm.audit-log-2026', $config['indices']['default']);
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
