<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Every answer that is not a success, and what the bundle decides it means.
 *
 * Two decisions live here and they are not the same one. **Which exception** says
 * whether the record is retried or given up on: a busy cluster and an unwell one are
 * asked again, a refusal is not, and getting that wrong either drops an audit record
 * or retries a document that will never be accepted. **Which words** say what an
 * operator reads at three in the morning, and the bundle writes those from the
 * cluster's machine-readable fields rather than its prose, because the prose quotes
 * the refused document.
 *
 * Both were reached only through a handful of happy-ish paths before, so the
 * boundaries between them — 400, 429, 499, 500 — and the several shapes an error body
 * arrives in were never asked about.
 */
final class HowAnAnswerIsClassifiedTest extends TestCase
{
    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function statuses(): iterable
    {
        // The boundaries, each from both sides. 429 is the cluster asking for a moment
        // and 5xx is the cluster being unwell: both are "ask again", and an audit record
        // must not be dropped for arriving during a busy hour. Everything else in the
        // 4xx range will answer the same way however many times it is asked.
        yield 'the first refusal' => [400, InvalidQueryException::class];
        yield 'not allowed' => [403, InvalidQueryException::class];
        yield 'ask again in a moment' => [429, TransportUnavailableException::class];
        yield 'the last refusal' => [499, InvalidQueryException::class];
        yield 'the first unwell answer' => [500, TransportUnavailableException::class];
        yield 'a gateway with nothing behind it' => [502, TransportUnavailableException::class];
    }

    #[DataProvider('statuses')]
    public function testWhatAStatusMeansForASearch(int $status, string $expected): void
    {
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response($status, ['error' => ['type' => 'illegal_argument_exception', 'reason' => 'something the cluster said']]));

        $this->expectException($expected);

        $gateway->search('audit_log', ['query' => ['match_all' => new \stdClass()]]);
    }

    #[DataProvider('statuses')]
    public function testWhatAStatusMeansForADocument(int $status, string $expected): void
    {
        // The same boundaries on the write path, where a refusal is a RequestRejected
        // rather than an InvalidQuery — one is about the query the bundle built, the
        // other about the document the application produced.
        $gateway = $this->gateway(static function (RequestInterface $request) use ($status): ResponseInterface {
            return $request->getMethod() === 'HEAD'
                ? self::response(200, [])
                : self::response($status, ['error' => ['type' => 'document_parsing_exception', 'reason' => 'something the cluster said']]);
        });

        $this->expectException($expected === InvalidQueryException::class ? RequestRejectedException::class : $expected);

        $gateway->index('audit_log', ['objectId' => 1], 'a');
    }

    public function testARefusedQuerySaysWhatItIsBeforeSayingWhy(): void
    {
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(400, ['error' => ['type' => 'illegal_argument_exception', 'reason' => 'Fielddata is disabled on [loggedAt]']]));

        try {
            $gateway->search('audit_log', ['sort' => [['loggedAt' => 'asc']]]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertSame('Elasticsearch rejected the query: Fielddata is disabled on [loggedAt]', $e->getMessage());
        }
    }

    public function testAWindowTheIndexRefusesCarriesTheAdviceAfterTheReasonRatherThanBeforeIt(): void
    {
        // The whole sentence, in order. What the cluster said comes first because that is
        // what an operator is searching their logs for; what to do about it follows.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(400, ['error' => ['root_cause' => [['type' => 'illegal_argument_exception', 'reason' => 'Result window is too large, from + size must be less than or equal to: [10000]']]]]));

        try {
            $gateway->search('audit_log', ['from' => 20_000]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertSame(
                'Elasticsearch rejected the query: Result window is too large, from + size must be less than or equal to: [10000]'
                .' — index.max_result_window on this index is lower than reader.max_result_window: raise it on the index,'
                .' lower the setting to match, or page with a cursor, which has no ceiling.',
                $e->getMessage(),
            );
        }
    }

    public function testAReasonThatIsNotAboutTheWindowIsPassedOnUnchanged(): void
    {
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(400, ['error' => ['reason' => 'No mapping found for [loggedAt] in order to sort on']]));

        try {
            $gateway->search('audit_log', ['sort' => [['loggedAt' => 'asc']]]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertSame('Elasticsearch rejected the query: No mapping found for [loggedAt] in order to sort on', $e->getMessage());
        }
    }

    public function testTheRootCauseIsPreferredToTheOuterError(): void
    {
        // Both shapes turn up, and they are not equally useful: the outer error is a
        // summary ("all shards failed"), the root cause is what actually happened.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(400, ['error' => [
            'type' => 'search_phase_execution_exception',
            'reason' => 'all shards failed',
            'root_cause' => [['type' => 'query_shard_exception', 'reason' => 'No mapping found for [loggedAt]']],
        ]]));

        try {
            $gateway->search('audit_log', ['sort' => [['loggedAt' => 'asc']]]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('No mapping found', $e->getMessage());
            self::assertStringNotContainsString('all shards failed', $e->getMessage());
        }
    }

    public function testAnAnswerThatIsNotJsonIsSaidToBeThat(): void
    {
        // An HTML error page from something between here and the cluster, most often —
        // and on a status the bundle would otherwise read a reason out of. "Not JSON" is
        // an answer an operator can act on; a parse error thrown from inside the reader
        // is not. (A 5xx never reaches this: it is an unreachable cluster whatever it
        // answered with, and the body is not read at all.)
        $gateway = $this->gateway(static fn (): ResponseInterface => new Response(400, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], '<html>400 Bad Request</html>'));

        try {
            $gateway->search('audit_log', ['query' => ['match_all' => new \stdClass()]]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('not JSON', $e->getMessage());
        }
    }

    public function testADocumentMentioningTheParameterIsNotARefusalOfTheParameter(): void
    {
        // The retry that drops include_source_on_error reads the cluster's wording, so it
        // has to read enough of it. A document with a field of that name — or any 400
        // that merely mentions it — is not the cluster saying it does not know the
        // parameter, and sending the write again without the protection would be the
        // wrong answer to a question nobody asked.
        $attempts = 0;
        $gateway = $this->gateway(function (RequestInterface $request) use (&$attempts): ResponseInterface {
            if ($request->getMethod() === 'HEAD') {
                return self::response(200, []);
            }

            // Only the write, not the info() that decides whether the parameter is sent
            // at all — counting that one would have read as a retry that never happened.
            if (str_contains($request->getUri()->getPath(), '_doc')) {
                ++$attempts;
            }

            return self::response(400, ['error' => ['type' => 'document_parsing_exception', 'reason' => 'failed to parse field [include_source_on_error] of type [boolean]']]);
        }, sourceOnError: null);

        try {
            $gateway->index('audit_log', ['include_source_on_error' => 'yes'], 'a');
            self::fail('expected the document to be refused');
        } catch (RequestRejectedException) {
        }

        self::assertSame(1, $attempts, 'a refused document was sent again with the protection removed');
    }

    public function testARefusedDocumentIsNamedByItsTypeFromWhereverTheClusterPutIt(): void
    {
        // The type is the whole of what a refused document is allowed to say — the
        // wording quotes the document itself and is dropped. So reading it from only one
        // of the two places it arrives in costs an operator the diagnosis entirely:
        // "rejected with status 400" instead of "document_parsing_exception on field
        // \"loggedAt\"".
        foreach ([
            'in the root cause' => ['error' => ['root_cause' => [['type' => 'document_parsing_exception', 'reason' => 'failed to parse field [loggedAt]']]]],
            'in the error itself' => ['error' => ['type' => 'document_parsing_exception', 'reason' => 'failed to parse field [loggedAt]']],
            // Both, saying different things — which is the ordinary shape of a real
            // refusal. The outer error is the phase that failed and the root cause is
            // what failed in it, so reading the outer one first would name the wrapper
            // and leave the operator no wiser.
            'in both, the root cause first' => ['error' => [
                'type' => 'illegal_argument_exception',
                'reason' => 'cannot index',
                'root_cause' => [['type' => 'document_parsing_exception', 'reason' => 'failed to parse field [loggedAt]']],
            ]],
        ] as $where => $body) {
            $gateway = $this->gateway(static function (RequestInterface $request) use ($body): ResponseInterface {
                return $request->getMethod() === 'HEAD' ? self::response(200, []) : self::response(400, $body);
            });

            try {
                $gateway->index('audit_log', ['objectId' => 1], 'a');
                self::fail('expected the document to be refused');
            } catch (RequestRejectedException $e) {
                self::assertStringContainsString('document_parsing_exception', $e->getMessage(), 'the type was not found '.$where);
                self::assertStringContainsString('field "loggedAt"', $e->getMessage(), 'nor the field, '.$where);
                self::assertStringNotContainsString('failed to parse', $e->getMessage(), 'and the wording travelled anyway');
            }
        }
    }

    private function gateway(callable $respond, ?bool $sourceOnError = false): ElasticsearchGateway
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

        return new ElasticsearchGateway(ClientBuilder::create()->setHosts(['http://es.test:9200'])->setHttpClient($http)->build(), $sourceOnError);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function response(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], (string) json_encode($body));
    }
}
