<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ClientFactory;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientFactoryTest extends TestCase
{
    public function testBuildsAClientForTheConfiguredHosts(): void
    {
        $client = ClientFactory::create(['http://localhost:9200', 'http://localhost:9201'], sslVerification: false, logger: new NullLogger());

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('localhost:9200', $client->getTransport()->getNodePool()->nextNode()->getUri()->getAuthority(), 'the first configured host is the first node');
    }

    public function testTheLoggerIsOptional(): void
    {
        self::assertInstanceOf(Client::class, ClientFactory::create(['http://localhost:9200']));
    }

    public function testWithoutHostsThereIsNothingToConnectTo(): void
    {
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('client.hosts or client.service');

        ClientFactory::create([]);
    }
}
