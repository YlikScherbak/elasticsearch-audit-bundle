<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The requests bulk() and the point-in-time methods actually put on the wire, and
 * what they make of the answers. The integration tests prove these work against a
 * cluster; these prove the shape without one, so a change to the body is caught by
 * the default suite rather than by a job that needs Docker.
 */
final class GatewayBulkAndPointInTimeTest extends TestCase
{
    public function testBulkSendsOneNdjsonRequestWithAnActionLinePerDocument(): void
    {
        $seen = null;
        $gateway = $this->gateway(function (RequestInterface $request) use (&$seen): ResponseInterface {
            if ($request->getMethod() === 'HEAD') {
                return self::response(200, []);
            }

            $seen = $request;

            return self::response(200, ['errors' => false, 'items' => [
                ['index' => ['status' => 201]], ['index' => ['status' => 201]], ['index' => ['status' => 201]],
            ]]);
        });

        $result = $gateway->bulk([
            ['index' => 'audit_log', 'document' => ['objectId' => 1], 'id' => 'a'],
            ['index' => 'audit_auth', 'document' => ['objectId' => 2], 'id' => null],
            ['index' => 'audit_log', 'document' => ['objectId' => 3], 'id' => 'c'],
        ]);

        self::assertNotNull($seen);
        self::assertSame('POST', $seen->getMethod());
        self::assertSame('/_bulk', $seen->getUri()->getPath(), 'one request for the batch, not one per index');
        // The client sends its own vendor media type (vnd.elasticsearch+x-ndjson; compatible-with=N);
        // what matters here is that the body is ndjson, not one JSON document.
        self::assertStringContainsString('x-ndjson', $seen->getHeaderLine('Content-Type'));

        $lines = array_map(
            static fn (string $line) => json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) $seen->getBody()), static fn (string $l) => $l !== '')),
        );

        self::assertCount(6, $lines, 'an action line and a document line each');
        self::assertSame(['index' => ['_index' => 'audit_log', '_id' => 'a']], $lines[0]);
        self::assertSame(['objectId' => 1], $lines[1]);
        self::assertSame(['index' => ['_index' => 'audit_auth']], $lines[2], 'no _id when the record has none: Elasticsearch assigns one');
        self::assertSame(['index' => ['_index' => 'audit_log', '_id' => 'c']], $lines[4], 'indices may differ within one batch');
        self::assertSame(3, $result->succeeded());
    }

    public function testBulkReportsRefusedItemsByPositionWithoutTheValuePreview(): void
    {
        $gateway = $this->gateway(static fn (RequestInterface $request) => $request->getMethod() === 'HEAD'
            ? self::response(200, [])
            : self::response(200, ['errors' => true, 'items' => [
                ['index' => ['status' => 201]],
                ['index' => ['status' => 400, 'error' => ['type' => 'document_parsing_exception', 'reason' => "failed to parse field [email] of type [integer]. Preview of field's value: 'alice@example.com'"]]],
            ]]));

        $result = $gateway->bulk([
            ['index' => 'audit_log', 'document' => ['objectId' => 1], 'id' => 'a'],
            ['index' => 'audit_log', 'document' => ['email' => 'alice@example.com'], 'id' => 'b'],
        ]);

        self::assertSame([1], array_keys($result->failures));
        self::assertSame(400, $result->failures[1]['status']);
        self::assertStringContainsString('failed to parse field [email]', $result->failures[1]['reason']);
        self::assertStringNotContainsString('alice@example.com', $result->failures[1]['reason'], 'the refused value is a person\'s data and stays out of the error path');
    }

    public function testBulkChecksEveryIndexOnceAndSendsNothingWhenOneIsMissing(): void
    {
        $requests = [];
        $gateway = $this->gateway(static function (RequestInterface $request) use (&$requests): ResponseInterface {
            $requests[] = $request->getMethod().' '.$request->getUri()->getPath();

            return $request->getUri()->getPath() === '/audit_auth' ? self::response(404, []) : self::response(200, []);
        });

        try {
            $gateway->bulk([
                ['index' => 'audit_log', 'document' => ['objectId' => 1], 'id' => 'a'],
                ['index' => 'audit_log', 'document' => ['objectId' => 2], 'id' => 'b'],
                ['index' => 'audit_auth', 'document' => ['objectId' => 3], 'id' => 'c'],
            ]);
            self::fail('expected IndexNotFoundException');
        } catch (IndexNotFoundException $e) {
            self::assertStringContainsString('audit_auth', $e->getMessage());
        }

        self::assertSame(['HEAD /audit_log', 'HEAD /audit_auth'], $requests, 'one lookup per distinct index, and no _bulk at all');
    }

    public function testAnEmptyBatchIsNoRequest(): void
    {
        $gateway = $this->gateway(static fn () => throw new \LogicException('nothing should be sent'));

        self::assertSame(0, $gateway->bulk([])->attempted);
    }

    public function testOpeningAPointInTimeAsksTheIndexAndReturnsItsId(): void
    {
        $seen = null;
        $gateway = $this->gateway(function (RequestInterface $request) use (&$seen): ResponseInterface {
            $seen = $request;

            return self::response(200, ['id' => 'pit-abc']);
        });

        self::assertSame('pit-abc', $gateway->openPointInTime('audit_log', '2m'));
        self::assertNotNull($seen);
        self::assertSame('POST', $seen->getMethod());
        self::assertSame('/audit_log/_pit', $seen->getUri()->getPath());
        self::assertStringContainsString('keep_alive=2m', $seen->getUri()->getQuery());
    }

    public function testAViewOpenedWithoutAnIdIsAnUnusableCluster(): void
    {
        $gateway = $this->gateway(static fn () => self::response(200, ['acknowledged' => true]));

        $this->expectException(TransportUnavailableException::class);
        $this->expectExceptionMessage('returned no id');

        $gateway->openPointInTime('audit_log', '1m');
    }

    public function testOpeningAViewOverAMissingIndexSaysWhichIndex(): void
    {
        $gateway = $this->gateway(static fn () => self::response(404, ['error' => ['type' => 'index_not_found_exception']]));

        $this->expectException(IndexNotFoundException::class);
        $this->expectExceptionMessage('"audit_log" does not exist');

        $gateway->openPointInTime('audit_log', '1m');
    }

    public function testSearchingAViewCarriesThePitInTheBodyAndNoIndexInThePath(): void
    {
        $seen = null;
        $gateway = $this->gateway(function (RequestInterface $request) use (&$seen): ResponseInterface {
            $seen = $request;

            return self::response(200, ['pit_id' => 'pit-renewed', 'hits' => ['total' => ['value' => 0], 'hits' => []]]);
        });

        $response = $gateway->searchPointInTime('pit-abc', '90s', ['size' => 2, 'sort' => [['loggedAt' => 'asc']]]);

        self::assertNotNull($seen);
        self::assertSame('/_search', $seen->getUri()->getPath(), 'the view says which indices; naming one as well is refused by Elasticsearch');

        $body = json_decode((string) $seen->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['id' => 'pit-abc', 'keep_alive' => '90s'], $body['pit'], 'every search extends the view');
        self::assertSame(2, $body['size'], 'the rest of the body is untouched');
        self::assertSame('pit-renewed', $response['pit_id'], 'the answer may carry a new id, and the caller uses it next');
    }

    public function testAQueryTheClusterRefusesInsideAViewIsStillAnInvalidQuery(): void
    {
        $gateway = $this->gateway(static fn () => self::response(400, ['error' => ['type' => 'illegal_argument_exception', 'reason' => 'No mapping found for [nope] in order to sort on']]));

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('No mapping found for [nope]');

        $gateway->searchPointInTime('pit-abc', '1m', ['sort' => [['nope' => 'asc']]]);
    }

    public function testClosingAViewSendsItsIdAndForgivesOneThatIsAlreadyGone(): void
    {
        $seen = null;
        $gateway = $this->gateway(function (RequestInterface $request) use (&$seen): ResponseInterface {
            $seen = $request;

            return self::response(200, ['succeeded' => true, 'num_freed' => 1]);
        });

        $gateway->closePointInTime('pit-abc');

        self::assertNotNull($seen);
        self::assertSame('DELETE', $seen->getMethod());
        self::assertSame('/_pit', $seen->getUri()->getPath());
        self::assertSame(['id' => 'pit-abc'], json_decode((string) $seen->getBody(), true, 512, \JSON_THROW_ON_ERROR));

        // An expired view is a 404, and there is nothing left to release.
        $expired = $this->gateway(static fn () => self::response(404, ['error' => ['type' => 'search_context_missing_exception']]));
        $expired->closePointInTime('pit-gone');

        self::assertTrue(true, 'closing a view that is already gone is not an error');
    }

    public function testAnUnreachableClusterWhileClosingStillSurfaces(): void
    {
        $gateway = $this->gateway(static function (): ResponseInterface {
            throw new class('Connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        });

        $this->expectException(TransportUnavailableException::class);

        $gateway->closePointInTime('pit-abc');
    }

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
