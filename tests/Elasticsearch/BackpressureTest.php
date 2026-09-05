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

    public function testAnAnswerNobodyCanParseIsNotQuotedBackAsTheReason(): void
    {
        // The sanitization has a fallback, and the fallback was $e->getMessage() — which
        // the client builds as "400 Bad Request: <the whole response body>". So a
        // well-formed Elasticsearch error was cleaned and anything else — a proxy, a WAF,
        // a gateway in front of the cluster — went into the exception in full.
        $gateway = $this->gateway(static fn (RequestInterface $r) => $r->getMethod() === 'HEAD'
            ? self::response(200, [])
            : new Response(400, ['Content-Type' => 'text/html', 'X-Elastic-Product' => 'Elasticsearch'], '<html>blocked: hunter2-the-actual-password</html>'));

        try {
            $gateway->index('audit_log', ['objectType' => 'order']);
            self::fail('the write should have been refused');
        } catch (RequestRejectedException $e) {
            self::assertStringNotContainsString('hunter2-the-actual-password', $e->getMessage());
            self::assertStringContainsString('400', $e->getMessage(), 'the status is the diagnostic that stays');
        }
    }

    public function testAnUnreachableClusterIsNamedWithoutRepeatingWhatItSaid(): void
    {
        // TransportUnavailableException::because() interpolated the cause's message into
        // its own — the same thing WriteFailedException stopped doing, for the same
        // reason: a 429 arrives as a ClientResponseException whose message is the whole
        // response body.
        $gateway = $this->gateway(static fn (RequestInterface $r) => $r->getMethod() === 'HEAD'
            ? self::response(200, [])
            : self::response(429, ['error' => ['type' => 'circuit_breaking_exception', 'reason' => "too many requests. Preview of field's value: 'hunter2-the-actual-password'"]]));

        try {
            $gateway->index('audit_log', ['objectType' => 'order']);
            self::fail('the write should have failed');
        } catch (TransportUnavailableException $e) {
            self::assertStringNotContainsString('hunter2-the-actual-password', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'the original is still one getPrevious() away');
        }
    }

    public function testAnIndexNobodyIsAllowedToLookAtIsNotAnIndexThatIsMissing(): void
    {
        // The client does not throw on HEAD — it suppresses its own exception for that
        // method — and asBool() is nothing but "2xx". So a role without
        // view_index_metadata, a 5xx, or a name the cluster rejects all came back as
        // "the index does not exist", and the bundle told the operator to run
        // audit:index:create. It exists; they cannot see it, which is a different day's
        // work.
        $gateway = $this->gateway(static fn (RequestInterface $r) => self::response(403, ['error' => ['reason' => 'action [indices:admin/get] is unauthorized for user [reader]']]));

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('unauthorized');

        $gateway->indexExists('audit_log');
    }

    public function testAnIndexTheClusterCannotAnswerAboutIsNotMissingEither(): void
    {
        $gateway = $this->gateway(static fn (RequestInterface $r) => self::response(503, ['error' => ['reason' => 'no master']]));

        $this->expectException(TransportUnavailableException::class);

        $gateway->indexExists('audit_log');
    }

    public function testAMissingIndexIsStillReportedAsMissing(): void
    {
        $gateway = $this->gateway(static fn (RequestInterface $r) => self::response(404, []));

        self::assertFalse($gateway->indexExists('audit_log'));
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

    public function testAnItemNobodyCanReadIsNotAnAnswerAtAll(): void
    {
        // "Whether this document was written is unknown" was recorded as a failure with
        // status 0 — and 0 is in no retry list, so the batch went to the failure
        // transport as permanently rejected. An unknown outcome is the one case where
        // re-sending is both safe (every document carries its id) and necessary.
        $this->expectException(TransportUnavailableException::class);
        $this->expectExceptionMessage('could not be read');

        BulkResult::fromResponse(['items' => [null, ['index' => ['status' => 201]]]], 2);
    }

    public function testAnItemWithoutAReadableStatusIsNotAnAnswerEither(): void
    {
        $this->expectException(TransportUnavailableException::class);

        BulkResult::fromResponse(['items' => [['index' => ['result' => 'created']]]], 1);
    }

    public function testEveryServerErrorIsTheClusterAskingAgain(): void
    {
        // The single-write path treats any 5xx as "not now"; the bulk path listed only
        // 503, so a per-item 500 was permanent — the same failure classified two ways
        // depending on how many records the flush happened to produce.
        foreach ([500, 502, 503, 504] as $status) {
            $result = BulkResult::fromResponse(['items' => [['index' => ['status' => $status]]]], 1);

            self::assertTrue($result->hasTransientFailures(), $status.' is the cluster asking for the document again');
        }

        $permanent = BulkResult::fromResponse(['items' => [['index' => ['status' => 400, 'error' => ['reason' => 'mapping']]]]], 1);

        self::assertFalse($permanent->hasTransientFailures(), 'a document the mapping refuses will be refused again');
    }

    public function testAFailureWithoutAnErrorObjectIsStillAFailure(): void
    {
        // Elasticsearch names the error, but the classification must follow the status:
        // a 500 with no "error" key was read as a success because the code looked for
        // the error object first.
        $result = BulkResult::fromResponse(['items' => [['index' => ['status' => 503]], ['index' => ['status' => 201]]]], 2);

        self::assertSame(1, $result->succeeded());
        self::assertSame(503, $result->failures[0]['status']);
        self::assertTrue($result->hasTransientFailures(), 'and 503 is still the cluster asking for it again');
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
