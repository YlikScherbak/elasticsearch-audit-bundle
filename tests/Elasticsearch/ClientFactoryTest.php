<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ClientFactory;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ClientLogGate;
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

        $wrapped = new ClientLogGate($logger);
        $wrapped->info('Request: GET http://elastic:s3cr3t@es:9209/_bulk', ['uri' => 'http://elastic:s3cr3t@es:9209/']);

        $observable = implode("\n", $lines);

        self::assertStringNotContainsString('s3cr3t', $observable);
        self::assertStringContainsString('//elastic:***@', $observable, 'the user stays, the password goes');
    }

    public function testTheDocumentItselfNeverReachesTheApplicationLog(): void
    {
        // elastic/transport logs "Headers: … Body: …" at debug for the request *and* the
        // response, so every audited document — the whole changes payload — went into the
        // application log once per write on any environment running at debug. The bundle
        // spends its effort keeping values out of the error path and then handed them to
        // the logger through the front door.
        $lines = [];

        (new ClientLogGate($this->recording($lines)))->debug('Headers: {"Content-Type":"application/json"}' . "\n" . 'Body: {"changes":{"password":{"new":"hunter2"}}}');

        self::assertSame([], $lines, 'the level that carries bodies is not passed on at all');
    }

    public function testAPsr7ObjectIsNotHandedOnEither(): void
    {
        // The info lines are the useful ones — method, URL, status — but the client puts
        // the whole request and response objects in their context, and a formatter that
        // serialises context reaches the body through them. The message survives; the
        // objects do not.
        $lines = [];
        $captured = [];
        $logger = new class($lines, $captured) extends \Psr\Log\AbstractLogger {
            /**
             * @param list<string>       $lines
             * @param list<array<mixed>> $captured
             */
            public function __construct(private array &$lines, private array &$captured)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
                $this->captured[] = $context;
            }
        };

        $request = new \GuzzleHttp\Psr7\Request('POST', 'http://es:9200/_bulk', [], '{"changes":{"password":{"new":"hunter2"}}}');

        (new ClientLogGate($logger))->info('Request: POST http://es:9200/_bulk', ['request' => $request, 'retry' => 0]);

        self::assertSame(['Request: POST http://es:9200/_bulk'], $lines);
        self::assertSame([['retry' => 0]], $captured, 'what is left is what a log line can say');
    }

    /**
     * @param list<string> $lines
     */
    private function recording(array &$lines): \Psr\Log\LoggerInterface
    {
        return new class($lines) extends \Psr\Log\AbstractLogger {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = (string) $message.' '.json_encode($context);
            }
        };
    }
}
