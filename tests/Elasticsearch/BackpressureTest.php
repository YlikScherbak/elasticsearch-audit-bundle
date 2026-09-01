<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Elastic\Elasticsearch\ClientBuilder;
use Http\Client\HttpAsyncClient;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Backpressure is not a refusal. A cluster that answers 429 is asking for the same
 * request again in a moment, and an audit trail that treats it as final loses records
 * exactly when there are most of them.
 */
final class BackpressureTest extends TestCase
{
    public function testAThrottledRequestIsNotAPermanentRefusal(): void
    {
        $gateway = $this->gateway(static fn (RequestInterface $r) => $r->getMethod() === 'HEAD'
            ? self::response(200, [])
            : self::response(429, ['error' => ['type' => 'circuit_breaking_exception', 'reason' => 'too many requests']]));

        $this->expectException(TransportUnavailableException::class);

        $gateway->index('audit_log', ['objectType' => 'order']);
    }

    public function testAnOverloadedClusterIsNotEither(): void
    {
        $gateway = $this->gateway(static fn (RequestInterface $r) => $r->getMethod() === 'HEAD'
            ? self::response(200, [])
            : self::response(503, ['error' => ['type' => 'unavailable_shards_exception', 'reason' => 'no shard available']]));

        $this->expectException(TransportUnavailableException::class);

        $gateway->index('audit_log', ['objectType' => 'order']);
    }

    public function testADocumentTheMappingRefusesStillIs(): void
    {
        $gateway = $this->gateway(static fn (RequestInterface $r) => $r->getMethod() === 'HEAD'
            ? self::response(200, [])
            : self::response(400, ['error' => ['type' => 'document_parsing_exception', 'reason' => 'failed to parse field']]));

        $this->expectException(RequestRejectedException::class);

        $gateway->index('audit_log', ['objectType' => 'order']);
    }

    public function testAMisconfiguredClientIsNotAnUnreachableCluster(): void
    {
        // answer() raises it from inside the closure call() wraps; the catch-all used to
        // turn a configuration mistake into "Elasticsearch is unreachable".
        $gateway = $this->gatewayWithAsyncClient();

        $this->expectException(NotConfiguredException::class);

        $gateway->indexExists('audit_log');
    }

    public function testACallWhoseAnswerIsIgnoredStillRefusesAnAsyncClient(): void
    {
        // createIndex() reads nothing from the response — but an asynchronous client
        // hands back a promise nobody waits on, and dropping it means the call may
        // never have completed while the method returns as if it had. The guard the
        // reading calls have applies to the fire-and-forget ones too.
        $gateway = $this->gatewayWithAsyncClient();

        $this->expectException(NotConfiguredException::class);

        $gateway->createIndex('audit_log', []);
    }

    public function testClosingAPointInTimeRefusesAnAsyncClientToo(): void
    {
        $gateway = $this->gatewayWithAsyncClient();

        $this->expectException(NotConfiguredException::class);

        $gateway->closePointInTime('pit-id');
    }

    public function testAPointInTimeThatCouldNotBeClosedIsNotSilence(): void
    {
        $gateway = $this->gateway(static fn () => self::response(403, ['error' => ['type' => 'security_exception', 'reason' => 'action is unauthorized']]));

        $this->expectException(RequestRejectedException::class);

        $gateway->closePointInTime('pit-id');
    }

    public function testAnExpiredPointInTimeStaysSilent(): void
    {
        $gateway = $this->gateway(static fn () => self::response(404, ['error' => ['type' => 'search_context_missing_exception', 'reason' => 'No search context found']]));

        $gateway->closePointInTime('pit-id');

        $this->expectNotToPerformAssertions();
    }

    public function testABulkAnswerWeCannotReadIsNotSuccess(): void
    {
        // Fewer items than were sent: the response was truncated, or it is not the
        // response to this request. Counting the missing ones as written is the one
        // answer an audit trail must not give.
        $this->expectException(TransportUnavailableException::class);
        $this->expectExceptionMessage('1 item(s), expected 3');

        BulkResult::fromResponse(['took' => 3, 'items' => [['index' => ['status' => 201]]]], 3);
    }

    public function testABulkAnswerWithNoItemsAtAllIsNotSuccessEither(): void
    {
        $this->expectException(TransportUnavailableException::class);

        BulkResult::fromResponse(['took' => 3], 5);
    }

    /**
     * @param callable(RequestInterface): ResponseInterface $respond
     */
    private function gateway(callable $respond, bool $async = false): ElasticsearchGateway
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

        $client = ClientBuilder::create()->setHosts(['http://es.test:9200'])->setHttpClient($http)->build();

        return new ElasticsearchGateway($async ? $client->setAsync(true) : $client);
    }

    private function gatewayWithAsyncClient(): ElasticsearchGateway
    {
        $async = new class implements HttpAsyncClient {
            public function sendAsyncRequest(RequestInterface $request): Promise
            {
                return new FulfilledPromise(BackpressureTest::answer());
            }
        };

        $client = ClientBuilder::create()->setHosts(['http://es.test:9200'])->setHttpClient(new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return BackpressureTest::answer();
            }
        })->build();

        $client->getTransport()->setAsyncClient($async);

        return new ElasticsearchGateway($client->setAsync(true));
    }

    public static function answer(): ResponseInterface
    {
        return self::response(200, []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function response(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], (string) json_encode($body));
    }
}
