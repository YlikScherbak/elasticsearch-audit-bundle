<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ClientFactory;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\UserinfoRedactingLogger;
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

        // The pool rotates from a random start, so ask it twice and look at the set, not the order.
        $pool = $client->getTransport()->getNodePool();
        $nodes = [$pool->nextNode()->getUri()->getAuthority(), $pool->nextNode()->getUri()->getAuthority()];
        sort($nodes);

        self::assertSame(['localhost:9200', 'localhost:9201'], $nodes, 'both configured hosts are nodes');
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

    public function testTheLoggerNeverSeesThePassword(): void
    {
        // The client logs every request URL, and http://user:secret@host is a documented
        // way to configure it — probed live: the password appeared once per call.
        $lines = [];
        $logger = new class($lines) extends \Psr\Log\AbstractLogger {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->lines[] = (string) $message.' '.json_encode($context);
            }
        };

        $wrapped = new UserinfoRedactingLogger($logger);
        $wrapped->info('Request: GET http://elastic:s3cr3t@es:9209/_bulk', ['uri' => 'http://elastic:s3cr3t@es:9209/']);

        $observable = implode("\n", $lines);

        self::assertStringNotContainsString('s3cr3t', $observable);
        self::assertStringContainsString('//elastic:***@', $observable, 'the user stays, the password goes');
    }
}
