<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The official client is final, so the gateway is exercised through a scripted
 * PSR-18 client underneath it — which also proves the mapping of HTTP statuses
 * onto the bundle's exceptions against the real client, not a stand-in.
 */
final class ElasticsearchGatewayTest extends TestCase
{
    public function testAMissingIndexIsReportedAsSuch(): void
    {
        $gateway = $this->gateway(static fn () => self::response(404, ['error' => ['type' => 'index_not_found_exception']]));

        $this->expectException(IndexNotFoundException::class);
        $this->expectExceptionMessage('"audit_log" does not exist');

        $gateway->search('audit_log', ['query' => ['match_all' => new \stdClass()]]);
    }

    public function testASearchElasticsearchRejectsIsAnInvalidQuery(): void
    {
        $gateway = $this->gateway(static fn () => self::response(400, ['error' => ['type' => 'illegal_argument_exception', 'reason' => 'Fielddata is disabled on [loggedAt]']]));

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('Fielddata is disabled on [loggedAt]');

        $gateway->search('audit_log', ['search_after' => ['garbage']]);
    }

    public function testAWriteElasticsearchRejectsIsNotAnUnreachableCluster(): void
    {
        $gateway = $this->gateway(static fn (RequestInterface $request) => $request->getMethod() === 'HEAD'
            ? self::response(200, [])
            : self::response(400, ['error' => ['type' => 'document_parsing_exception', 'reason' => 'failed to parse field [objectId] of type [long]']]));

        try {
            $gateway->index('audit_log', ['objectId' => 'abc']);
            self::fail('expected an exception');
        } catch (RequestRejectedException $e) {
            self::assertStringContainsString('rejected', $e->getMessage());
            self::assertStringContainsString('failed to parse field [objectId]', $e->getMessage());
            self::assertNotInstanceOf(TransportUnavailableException::class, $e);
        }
    }

    public function testAnUnreachableClusterIsStillReportedAsSuch(): void
    {
        $gateway = $this->gateway(static function (RequestInterface $request): ResponseInterface {
            throw new class('Connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        });

        $this->expectException(TransportUnavailableException::class);
        $this->expectExceptionMessage('unreachable');

        $gateway->info();
    }

    public function testAnIndexThatDisappearsIsForgottenAndCheckedAgain(): void
    {
        $exists = true;
        $requests = [];
        $gateway = $this->gateway(static function (RequestInterface $request) use (&$exists, &$requests): ResponseInterface {
            $requests[] = $request->getMethod();

            if ($request->getMethod() === 'HEAD') {
                return self::response($exists ? 200 : 404, []);
            }

            return $exists
                ? self::response(201, ['result' => 'created'])
                : self::response(404, ['error' => ['type' => 'index_not_found_exception']]); // a cluster with auto_create_index off
        });

        $gateway->index('audit_log', ['a' => 1]);
        $gateway->index('audit_log', ['a' => 2]);
        self::assertSame(['HEAD', 'POST', 'POST'], $requests, 'existence is checked once, then remembered');

        $exists = false;

        try {
            $gateway->index('audit_log', ['a' => 3]);
            self::fail('expected IndexNotFoundException');
        } catch (IndexNotFoundException) {
        }

        $requests = [];
        try {
            $gateway->index('audit_log', ['a' => 4]);
        } catch (IndexNotFoundException) {
        }

        self::assertSame(['HEAD'], $requests, 'after a 404 the cached answer is dropped and existence is checked again');
    }

    public function testAWriteNeverCreatesAnIndexOnItsOwn(): void
    {
        $requests = [];
        $gateway = $this->gateway(static function (RequestInterface $request) use (&$requests): ResponseInterface {
            $requests[] = $request->getMethod().' '.$request->getUri()->getPath();

            return $request->getMethod() === 'HEAD' ? self::response(404, []) : self::response(201, ['result' => 'created']);
        });

        try {
            $gateway->index('audit_log', ['objectType' => 'order']);
            self::fail('expected an exception');
        } catch (IndexNotFoundException) {
        }

        self::assertSame(['HEAD /audit_log'], $requests, 'nothing was sent that Elasticsearch could auto-create the index from');
    }

    public function testTheIndexIsLookedUpOncePerProcess(): void
    {
        $requests = [];
        $gateway = $this->gateway(static function (RequestInterface $request) use (&$requests): ResponseInterface {
            $requests[] = $request->getMethod().' '.$request->getUri()->getPath();

            return $request->getMethod() === 'HEAD' ? self::response(200, []) : self::response(201, ['result' => 'created']);
        });

        $gateway->index('audit_log', ['objectType' => 'order']);
        $gateway->index('audit_log', ['objectType' => 'order'], 'fixed-id');

        self::assertSame(['HEAD /audit_log', 'POST /audit_log/_doc', 'PUT /audit_log/_doc/fixed-id'], $requests);
    }

    public function testAnIndexTheGatewayCreatedNeedsNoLookup(): void
    {
        $requests = [];
        $gateway = $this->gateway(static function (RequestInterface $request) use (&$requests): ResponseInterface {
            $requests[] = $request->getMethod().' '.$request->getUri()->getPath();

            return self::response(200, ['acknowledged' => true]);
        });

        $gateway->createIndex('audit_log', ['settings' => []]);
        $gateway->index('audit_log', ['objectType' => 'order']);

        self::assertSame(['PUT /audit_log', 'POST /audit_log/_doc'], $requests);
    }

    public function testASuccessfulSearchIsReturnedAsAnArray(): void
    {
        $gateway = $this->gateway(static fn () => self::response(200, ['hits' => ['total' => ['value' => 1], 'hits' => [['_id' => 'a', '_source' => []]]]]));

        self::assertSame(1, $gateway->search('audit_log', [])['hits']['total']['value']);
    }

    /**
     * @param callable(RequestInterface): ResponseInterface $respond
     */
    private function gateway(callable $respond): ElasticsearchGateway
    {
        $http = new class($respond) implements ClientInterface {
            /** @param callable(RequestInterface): ResponseInterface $respond */
            public function __construct(private $respond)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->respond)($request);
            }
        };

        return new ElasticsearchGateway(ClientBuilder::create()->setHosts(['http://es.test:9200'])->setHttpClient($http)->build());
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function response(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], (string) json_encode($body));
    }
}
