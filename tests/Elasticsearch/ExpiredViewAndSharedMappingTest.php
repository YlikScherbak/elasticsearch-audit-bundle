<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\ElasticsearchGateway;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Two readers of a cluster's answer that had one test each and several branches.
 *
 * **A point in time that expired** has to be recognised from what the cluster names
 * rather than from the sentence it writes, because the sentence is a message and a
 * message changes between versions. Recognising it is what turns "your query is
 * wrong" into "the view expired, raise the keep-alive" — an operator reading the
 * first will go looking at the query.
 *
 * **A mapping behind an alias** is several mappings, and a field counts as mapped
 * only where every index behind the alias maps it the same way. Same way means the
 * same content: two indices, one created from a template and one grown by
 * putMapping, spell an identical mapping in a different key order, and comparing
 * them by order dropped fields that were perfectly fine — which audit:check then
 * reported as missing and audit:index:sync obligingly re-added.
 */
final class ExpiredViewAndSharedMappingTest extends TestCase
{
    public function testAViewThatExpiredIsRecognisedByTheTypeTheClusterNames(): void
    {
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(400, [
            'error' => ['type' => 'search_context_missing_exception', 'reason' => 'No search context found for id [42]'],
        ]));

        try {
            $gateway->searchPointInTime('pit-id', '1m', ['query' => []]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('the point in time expired between two batches', $e->getMessage());
            self::assertStringContainsString('keep-alive 1m', $e->getMessage(), 'and says what the setting was');
            self::assertStringContainsString('point_in_time_keep_alive', $e->getMessage(), 'and which one to raise');
        }
    }

    public function testTheTypeIsReadFromTheRootCauseAsWell(): void
    {
        // Where a real cluster puts it: the outer error is the search phase failing, and
        // what actually happened is one level down.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(404, [
            'error' => [
                'type' => 'search_phase_execution_exception',
                'reason' => 'all shards failed',
                'root_cause' => [['type' => 'search_context_missing_exception', 'reason' => 'No search context found']],
            ],
        ]));

        try {
            $gateway->searchPointInTime('pit-id', '5m', ['query' => []]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('the point in time expired', $e->getMessage());
        }
    }

    public function testAQueryThatIsSimplyWrongIsNotBlamedOnTheView(): void
    {
        // The other side, and the one that matters for a reader: telling somebody their
        // view expired when their sort field does not exist sends them to change a
        // setting that was never the problem.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(400, [
            'error' => ['type' => 'query_shard_exception', 'reason' => 'No mapping found for [nope] in order to sort on'],
        ]));

        try {
            $gateway->searchPointInTime('pit-id', '1m', ['sort' => [['nope' => 'asc']]]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringNotContainsString('expired', $e->getMessage());
        }
    }

    public function testAnAnswerWithNoReadableBodyIsNotGuessedToBeAnExpiredView(): void
    {
        // Neither a type to read nor the words in the message. Guessing "expired" here
        // would be inventing a cause, and the caller can see the status.
        $gateway = $this->gateway(static fn (): ResponseInterface => new Response(400, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], 'not json at all'));

        try {
            $gateway->searchPointInTime('pit-id', '1m', ['query' => []]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringNotContainsString('expired', $e->getMessage());
        }
    }

    public function testTheWordsAreStillAFallbackWhereThereIsNoType(): void
    {
        // A response shaped in none of the ways above, saying it in prose. The type is
        // the reliable half; this is the half that keeps working on an answer written by
        // something that is not quite Elasticsearch.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(400, [
            'error' => ['reason' => 'No search context found for id [42]'],
        ]));

        try {
            $gateway->searchPointInTime('pit-id', '1m', ['query' => []]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('the point in time expired', $e->getMessage());
        }
    }

    public function testTheTypeIsEnoughWhenTheWordsSayNothing(): void
    {
        // The two halves pulled apart. Everything above says "search context" in the
        // prose as well, so the type could have been read wrongly — or not read at all —
        // and the fallback would have covered it. A cluster that names the type and
        // words it differently is the case that tells them apart, and it is the case
        // that will turn up when somebody rewords the message upstream.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(404, [
            'error' => ['type' => 'search_context_missing_exception', 'reason' => 'the view is gone'],
        ]));

        try {
            $gateway->searchPointInTime('pit-id', '1m', ['query' => []]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('the point in time expired', $e->getMessage());
        }
    }

    public function testTheTypeInTheRootCauseIsEnoughOnItsOwnToo(): void
    {
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(404, [
            'error' => [
                'type' => 'search_phase_execution_exception',
                'reason' => 'all shards failed',
                'root_cause' => [['type' => 'search_context_missing_exception', 'reason' => 'the view is gone']],
            ],
        ]));

        try {
            $gateway->searchPointInTime('pit-id', '1m', ['query' => []]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('the point in time expired', $e->getMessage());
        }
    }

    public function testATypeThatIsNotTheMissingContextIsNotTreatedAsOne(): void
    {
        // And the other direction, without the prose helping either way: a refusal that
        // names some other type is not an expired view, whatever else is true of it.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(404, [
            'error' => ['type' => 'index_not_found_exception', 'reason' => 'no such index'],
        ]));

        try {
            $gateway->searchPointInTime('pit-id', '1m', ['query' => []]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringNotContainsString('expired', $e->getMessage());
        }
    }

    public function testABodyThatIsNotAnObjectIsNotSearchedForATypeAtAll(): void
    {
        // Valid JSON, and nothing a type can be read out of. Reading on would be asking
        // for array offsets of a scalar; the words are all that is left, and they say
        // nothing here either.
        $gateway = $this->gateway(static fn (): ResponseInterface => new Response(404, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], '"gone"'));

        try {
            $gateway->searchPointInTime('pit-id', '1m', ['query' => []]);
            self::fail('expected the query to be refused');
        } catch (InvalidQueryException $e) {
            self::assertStringNotContainsString('expired', $e->getMessage());
        }
    }

    public function testAFieldCountsAsMappedOnlyWhereEveryIndexBehindTheAliasHasIt(): void
    {
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(200, [
            'audit_log-000001' => ['mappings' => ['properties' => [
                'objectId' => ['type' => 'keyword'],
                'tenant' => ['type' => 'keyword'],
            ]]],
            'audit_log-000002' => ['mappings' => ['properties' => [
                'objectId' => ['type' => 'keyword'],
            ]]],
        ]));

        self::assertSame(['objectId' => ['type' => 'keyword']], $gateway->mapping('audit_log'));
    }

    public function testTheSameMappingSpeltInAnotherOrderIsTheSameMapping(): void
    {
        // The reason the comparison is by content. One index was created from a
        // template, the other grew by putMapping, and JSON has no opinion about key
        // order — but an order-sensitive comparison would have called these different
        // and dropped the field, which audit:check then reports as missing.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(200, [
            'audit_log-000001' => ['mappings' => ['properties' => [
                'loggedAt' => ['type' => 'date', 'format' => 'strict_date_optional_time||epoch_millis'],
                'changes' => ['type' => 'object', 'enabled' => false],
            ]]],
            'audit_log-000002' => ['mappings' => ['properties' => [
                'loggedAt' => ['format' => 'strict_date_optional_time||epoch_millis', 'type' => 'date'],
                'changes' => ['enabled' => false, 'type' => 'object'],
            ]]],
        ]));

        self::assertSame(
            [
                'loggedAt' => ['type' => 'date', 'format' => 'strict_date_optional_time||epoch_millis'],
                'changes' => ['type' => 'object', 'enabled' => false],
            ],
            $gateway->mapping('audit_log'),
        );
    }

    public function testTheOrderIsSortedOnBothSidesOfTheComparison(): void
    {
        // The mirror of the test above, and not a duplicate of it: sorting one side and
        // trusting the other happens to be sorted already is a comparison that works
        // until the indices are listed the other way round. Which they will be — the
        // order of a mapping response is the order the cluster felt like.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(200, [
            'audit_log-000001' => ['mappings' => ['properties' => [
                'loggedAt' => ['format' => 'strict_date_optional_time', 'type' => 'date'],
            ]]],
            'audit_log-000002' => ['mappings' => ['properties' => [
                'loggedAt' => ['type' => 'date', 'format' => 'strict_date_optional_time'],
            ]]],
        ]));

        self::assertSame(['loggedAt' => ['format' => 'strict_date_optional_time', 'type' => 'date']], $gateway->mapping('audit_log'));
    }

    public function testAFieldSpeltAsAnObjectOnOneIndexAndAWordOnTheOtherIsNotShared(): void
    {
        // One side an object, the other a bare value. Comparing those as though both
        // were objects reads the keys of something that has none; comparing them as
        // though both were values compares an array to a string. Neither is "the same
        // mapping", and saying so is the whole answer.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(200, [
            'audit_log-000001' => ['mappings' => ['properties' => ['objectId' => ['type' => 'keyword']]]],
            'audit_log-000002' => ['mappings' => ['properties' => ['objectId' => 'keyword']]],
        ]));

        self::assertSame([], $gateway->mapping('audit_log'));
    }

    public function testAFieldNestedIdenticallyIsShared(): void
    {
        // The recursive half: fields with fields. Multi-field mappings (a keyword under
        // a text, for aggregating on) are the ordinary shape of this, and they have to
        // survive the comparison rather than being dropped for being deep.
        $nested = ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword', 'ignore_above' => 256]]];
        $sameOtherOrder = ['fields' => ['raw' => ['ignore_above' => 256, 'type' => 'keyword']], 'type' => 'text'];

        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(200, [
            'audit_log-000001' => ['mappings' => ['properties' => ['actor' => $nested]]],
            'audit_log-000002' => ['mappings' => ['properties' => ['actor' => $sameOtherOrder]]],
        ]));

        self::assertSame(['actor' => $nested], $gateway->mapping('audit_log'));
    }

    public function testAFieldNestedDifferentlyIsNot(): void
    {
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(200, [
            'audit_log-000001' => ['mappings' => ['properties' => ['actor' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword']]]]]],
            'audit_log-000002' => ['mappings' => ['properties' => ['actor' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'text']]]]]],
        ]));

        self::assertSame([], $gateway->mapping('audit_log'));
    }

    public function testAFieldTwoIndicesDisagreeAboutIsNotShared(): void
    {
        // Content, though, and not merely the same keys: a field mapped as text on one
        // index and keyword on the other is exactly the drift audit:check exists to see.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(200, [
            'audit_log-000001' => ['mappings' => ['properties' => ['objectId' => ['type' => 'keyword']]]],
            'audit_log-000002' => ['mappings' => ['properties' => ['objectId' => ['type' => 'text']]]],
        ]));

        self::assertSame([], $gateway->mapping('audit_log'));
    }

    public function testAnIndexWithNoMappingsAtAllSharesNothing(): void
    {
        // A freshly rolled-over index that nothing has written to yet answers without a
        // properties object. Reading that as "no opinion" would let a field count as
        // shared when one index behind the alias does not have it.
        $gateway = $this->gateway(static fn (): ResponseInterface => self::response(200, [
            'audit_log-000001' => ['mappings' => ['properties' => ['objectId' => ['type' => 'keyword']]]],
            'audit_log-000002' => ['mappings' => new \stdClass()],
        ]));

        self::assertSame([], $gateway->mapping('audit_log'));
    }

    public function testTheMappingIsAskedForTheIndexItWasAskedAbout(): void
    {
        $paths = [];
        $gateway = $this->gateway(static function (RequestInterface $request) use (&$paths): ResponseInterface {
            $paths[] = $request->getUri()->getPath();

            return self::response(200, ['audit_auth_log' => ['mappings' => ['properties' => []]]]);
        });

        $gateway->mapping('audit_auth_log');

        self::assertSame(['/audit_auth_log/_mapping'], $paths);
    }

    public function testASearchCarriesBothTheIndexAndTheBody(): void
    {
        $seen = null;
        $gateway = $this->gateway(static function (RequestInterface $request) use (&$seen): ResponseInterface {
            $seen = $request->getUri()->getPath().' '.(string) $request->getBody();

            return self::response(200, ['hits' => ['hits' => [], 'total' => ['value' => 0]]]);
        });

        $gateway->search('audit_auth_log', ['query' => ['term' => ['objectType' => 'auth']]]);

        self::assertIsString($seen);
        self::assertStringContainsString('/audit_auth_log/_search', $seen);
        self::assertStringContainsString('objectType', $seen, 'the query was sent without its body');
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

        return new ElasticsearchGateway(ClientBuilder::create()->setHosts(['http://es.test:9200'])->setHttpClient($http)->build(), false);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function response(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], (string) json_encode($body));
    }
}
