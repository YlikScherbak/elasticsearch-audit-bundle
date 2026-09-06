<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Examples;

use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Contract\RecordDecoratorInterface;
use Borsche\ElasticsearchAuditBundle\Examples\Reading\CountingWithAggregations;
use Borsche\ElasticsearchAuditBundle\Examples\Reading\HistoryController;
use Borsche\ElasticsearchAuditBundle\Examples\Reading\NameTheActorDecorator;
use Borsche\ElasticsearchAuditBundle\Examples\Reading\OnlyWhatThisViewerMaySeeExtension;
use Borsche\ElasticsearchAuditBundle\Examples\Reading\ReadableStatusDecorator;
use Borsche\ElasticsearchAuditBundle\Examples\Reading\ReadingTheHistory;
use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\AuditReader;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The reading examples, run.
 *
 * The in-memory gateway answers a search with everything it holds — query
 * semantics belong to the integration tests — so what is asserted here is what the
 * examples actually promise: the request they build, the boundary an extension
 * puts on it, and the shape a decorator or an endpoint hands on. Those are the
 * parts that break when a signature moves.
 */
final class ReadingExamplesTest extends TestCase
{
    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->gateway->indices['audit_log'] = [];
    }

    public function testTheFiltersReachElasticsearchAsTheExampleSpellsThem(): void
    {
        (new ReadingTheHistory($this->reader()))->whatThisPersonDidLastWeek('alice');

        $body = $this->gateway->searches[0]['body'];
        $filters = $body['query']['bool']['filter'] ?? [];

        self::assertIsArray($filters);
        $spelled = json_encode($filters, \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('{"term":{"source":"alice"}}', $spelled, 'the actor is a term filter, not a match');
        self::assertStringContainsString('"event":["update","remove"]', $spelled);
        self::assertStringContainsString('orderCountry', $spelled, 'an attribute filters like a base field');
        self::assertStringContainsString('"exists"', $spelled, 'whereExists asks the cluster whether the field is there');
        self::assertStringContainsString('"range"', $spelled, 'and whereBetween is a range');
    }

    public function testOneObjectsHistoryAsksForThatObjectOnly(): void
    {
        (new ReadingTheHistory($this->reader()))->forOneOrder(42);

        $body = $this->gateway->searches[0]['body'];

        self::assertSame(20, $body['size']);
        self::assertSame(0, $body['from'], 'page 1');
        self::assertStringContainsString('{"term":{"objectId":42}}', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function testAnExportWalksTheWholeResultSetThroughAPointInTime(): void
    {
        $this->gateway->documents['audit_log'] = [
            $this->document(1, 'alice'),
            $this->document(2, 'bob'),
        ];

        $entries = iterator_to_array((new ReadingTheHistory($this->reader()))->exportEverything(new \DateTimeImmutable('-1 day')), false);

        self::assertCount(2, $entries);
        self::assertContainsOnlyInstancesOf(AuditEntry::class, $entries);
        self::assertNotEmpty($this->gateway->pointsInTime, 'consistent: true reads through a point in time');
    }

    public function testTheDecoratorNamesTheActorOncePerPageRatherThanOncePerLine(): void
    {
        $this->gateway->documents['audit_log'] = [
            $this->document(1, 'u-7'),
            $this->document(2, 'u-7'),
            $this->document(3, 'u-9'),
        ];

        $decorator = new NameTheActorDecorator(['u-7' => 'Alice', 'u-9' => 'Bob']);
        $page = $this->reader(decorators: [$decorator])->find(AuditQuery::for('order'));

        self::assertSame(['Alice', 'Alice', 'Bob'], array_map(
            static fn (AuditEntry $entry): mixed => $entry->extra['actorName'],
            $page->entries,
        ));
        self::assertSame('u-7', $page->entries[0]->actor, 'what is stored is untouched');
        self::assertArrayNotHasKey('actorName', $page->entries[0]->toDocument(), 'extra is never part of the document');
    }

    public function testTheOtherDecoratorReplacesTheValueRatherThanAddingBesideIt(): void
    {
        $this->gateway->documents['audit_log'] = [
            ['objectType' => 'order', 'objectId' => 1, 'event' => 'update', 'loggedAt' => '2026-09-06 10:00:00', 'source' => 'u-7', 'changes' => ['status' => ['old' => 'draft', 'new' => 'approved']]],
        ];

        $page = $this->reader(decorators: [new ReadableStatusDecorator()])->find(AuditQuery::for('order'));

        self::assertSame(['old' => 'Draft', 'new' => 'Approved'], $page->entries[0]->changes['status']);
    }

    public function testTheExtensionCanOnlyNarrowWhatWasAsked(): void
    {
        $extension = new OnlyWhatThisViewerMaySeeExtension(['alice', 'bob']);

        // Asked for two, allowed both.
        $this->reader(extensions: [$extension])->find(AuditQuery::for('order')->withActors('alice', 'bob'));
        self::assertStringContainsString('{"terms":{"source":["alice","bob"]}}', json_encode($this->gateway->searches[0]['body'], \JSON_THROW_ON_ERROR));

        // Asked for one they may not see: nothing, rather than everyone allowed.
        $searchesBefore = \count($this->gateway->searches);
        $page = $this->reader(extensions: [$extension])->find(AuditQuery::for('order')->withActors('carol'));

        self::assertTrue($page->isEmpty());
        self::assertCount($searchesBefore, $this->gateway->searches, 'a query that can match nothing is answered without a request');
    }

    public function testAnAuditorSeesEverythingAndAViewerWithNoTeamSeesNothing(): void
    {
        $auditor = new OnlyWhatThisViewerMaySeeExtension([], isAuditor: true);
        $this->reader(extensions: [$auditor])->find(AuditQuery::for('order'));
        self::assertCount(1, $this->gateway->searches, 'the auditor asks the cluster');

        $nobody = new OnlyWhatThisViewerMaySeeExtension([]);
        $page = $this->reader(extensions: [$nobody])->find(AuditQuery::for('order'));

        self::assertTrue($page->isEmpty());
        self::assertCount(1, $this->gateway->searches, 'and the one who may see nothing does not');
    }

    public function testTheAggregationsAreWrappedInTheQuerysOwnBoundary(): void
    {
        $this->gateway->respondToSearch = static fn (): array => [
            'hits' => ['total' => ['value' => 3], 'hits' => []],
            'aggregations' => ['by_actor' => ['buckets' => [
                ['key' => 'alice', 'doc_count' => 2],
                ['key' => 'bob', 'doc_count' => 1],
            ]]],
        ];

        $counts = (new CountingWithAggregations($this->reader()))->busiestActors(new \DateTimeImmutable('-30 days'));

        self::assertSame(['alice' => 2, 'bob' => 1], $counts);

        $body = $this->gateway->searches[0]['body'];
        self::assertSame(0, $body['size']);
        self::assertArrayHasKey('query', $body, "raw() wraps the caller's body in the query's filters");
    }

    public function testAnEmptyAggregationResponseIsReadRatherThanFatal(): void
    {
        // What a viewer who may see nothing gets: no aggregations key at all.
        $counts = (new CountingWithAggregations($this->reader(extensions: [new OnlyWhatThisViewerMaySeeExtension([])])))
            ->busiestActors(new \DateTimeImmutable('-30 days'));

        self::assertSame([], $counts);
    }

    public function testTheEndpointAnswersWithThePageAndWithTheCallersMistakes(): void
    {
        $this->gateway->documents['audit_log'] = [$this->document(1, 'u-7')];

        $controller = new HistoryController($this->reader());

        $response = $controller(Request::create('/api/history', 'GET', ['objectType' => 'order', 'objectId' => '1']));
        self::assertSame(200, $response->getStatusCode());

        /** @var array{items: list<mixed>, pagination: array<string, mixed>} $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['items']);
        self::assertArrayHasKey('nextCursor', $payload['pagination']);

        // A cursor from somebody else's query is the caller's mistake, and says so.
        $refused = $controller(Request::create('/api/history', 'GET', ['cursor' => 'not-a-cursor']));
        self::assertSame(400, $refused->getStatusCode());
    }

    public function testTheEndpointAnswersAnIndexThatIsNotThereWithAnEmptyPage(): void
    {
        $gateway = new InMemoryGateway();   // nothing written, no index
        $controller = new HistoryController(new AuditReader($gateway, new IndexResolver('audit_log')));

        $response = $controller(Request::create('/api/history', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"items":[]', (string) $response->getContent());
    }

    /**
     * @param iterable<QueryExtensionInterface>  $extensions
     * @param iterable<RecordDecoratorInterface> $decorators
     */
    private function reader(iterable $extensions = [], iterable $decorators = []): AuditReader
    {
        return new AuditReader(
            $this->gateway,
            new IndexResolver('audit_log'),
            extensions: $extensions,
            decorators: $decorators,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function document(int $id, string $actor): array
    {
        return [
            'objectType' => 'order',
            'objectId' => $id,
            'event' => 'update',
            'loggedAt' => sprintf('2026-09-06 10:00:%02d', $id),
            'source' => $actor,
            'changes' => ['status' => ['old' => 'draft', 'new' => 'approved']],
        ];
    }
}
