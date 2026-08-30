<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\AuditException;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;

/**
 * A Gateway that keeps everything in arrays, so unit tests can assert on exactly
 * what would have reached Elasticsearch. search() is deliberately dumb — it
 * returns every stored document; query semantics belong to the integration tests.
 */
final class InMemoryGateway implements GatewayInterface
{
    /** @var array<string, list<array<string, mixed>>> index => documents */
    public array $documents = [];

    /** @var array<string, array<string, mixed>> index => definition */
    public array $indices = [];

    /** @var array<string, list<string|null>> index => the _id each document was written with, in order */
    public array $ids = [];

    public ?\Throwable $failWith = null;

    /** @var array<string, \Throwable> method name => what that method throws (a bundle exception as is, anything else wrapped as TransportUnavailableException) */
    public array $failOn = [];

    /** @var list<array{index: string, body: array<string, mixed>}> every search() call, for asserting on the request */
    public array $searches = [];

    /** @var (callable(string, array<string, mixed>): array<string, mixed>)|null scripted search responses */
    public $respondToSearch = null;

    /** @var (callable(string, array<string, mixed>): void)|null observes every index() call */
    public $onIndex = null;

    public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
    {
        $this->maybeFail(__FUNCTION__);

        if ($this->onIndex !== null) {
            ($this->onIndex)($index, $document);
        }

        // Same id, same document: what a retried write does on the real cluster.
        if ($id !== null && ($at = array_search($id, $this->ids[$index] ?? [], true)) !== false) {
            $this->documents[$index][$at] = $document;

            return;
        }

        $this->documents[$index][] = $document;
        $this->ids[$index][] = $id;
    }

    public function search(string $index, array $body): array
    {
        $this->maybeFail();
        $this->searches[] = ['index' => $index, 'body' => $body];

        if ($this->respondToSearch !== null) {
            return ($this->respondToSearch)($index, $body);
        }

        if (!isset($this->documents[$index]) && !isset($this->indices[$index])) {
            throw IndexNotFoundException::forIndex($index);
        }

        $hits = array_map(
            static fn (array $doc, int $i) => ['_id' => (string) $i, '_source' => $doc],
            $this->documents[$index] ?? [],
            array_keys($this->documents[$index] ?? []),
        );

        return ['hits' => ['total' => ['value' => \count($hits)], 'hits' => $hits]];
    }

    /** @var list<list<array{index: string, document: array<string, mixed>, id: string|null}>> every bulk() call */
    public array $bulks = [];

    /** @var (callable(array<string, mixed>, int): bool)|null decides per item whether the cluster rejects it */
    public $rejectInBulk = null;

    /** Status the scripted rejection carries: 400 refuses the document, 429 asks for it again later. */
    public int $rejectInBulkStatus = 400;

    /** @var array<string, array{index: string, snapshot: list<array<string, mixed>>, closed: bool, searches: int}> open and closed points in time */
    public array $pointsInTime = [];

    public function bulk(array $items): BulkResult
    {
        $this->maybeFail();
        $this->bulks[] = $items;

        $failures = [];

        foreach ($items as $position => $item) {
            if ($this->rejectInBulk !== null && ($this->rejectInBulk)($item['document'], $position)) {
                $failures[$position] = ['status' => $this->rejectInBulkStatus, 'reason' => 'rejected by the test'];
                continue;
            }

            $this->index($item['index'], $item['document'], $item['id']);
        }

        return new BulkResult(\count($items), $failures);
    }

    public function openPointInTime(string $index, string $keepAlive): string
    {
        $this->maybeFail();

        // A scripted responder stands in for the index; otherwise it has to exist, as on the cluster.
        if ($this->respondToSearch === null && !isset($this->documents[$index]) && !isset($this->indices[$index])) {
            throw IndexNotFoundException::forIndex($index);
        }

        $id = 'pit-'.(\count($this->pointsInTime) + 1);
        // A frozen view: what is written after this moment is not part of it.
        $this->pointsInTime[$id] = ['index' => $index, 'snapshot' => $this->documents[$index] ?? [], 'closed' => false, 'searches' => 0];

        return $id;
    }

    public function searchPointInTime(string $pitId, string $keepAlive, array $body): array
    {
        $this->maybeFail();
        $this->searches[] = ['index' => null, 'pit' => $pitId, 'body' => $body];

        if (!isset($this->pointsInTime[$pitId]) || $this->pointsInTime[$pitId]['closed']) {
            throw new InvalidQueryException(sprintf('The point in time %s is closed or unknown.', $pitId));
        }

        ++$this->pointsInTime[$pitId]['searches'];

        if ($this->respondToSearch !== null) {
            return ($this->respondToSearch)($this->pointsInTime[$pitId]['index'], $body) + ['pit_id' => $pitId];
        }

        $snapshot = $this->pointsInTime[$pitId]['snapshot'];
        $hits = array_map(
            static fn (array $doc, int $i) => ['_id' => (string) $i, '_source' => $doc, 'sort' => [$doc['loggedAt'] ?? '', $i]],
            $snapshot,
            array_keys($snapshot),
        );

        // Honour search_after on the position tiebreaker, so an iterate() over the snapshot terminates.
        $after = $body['search_after'][1] ?? null;
        if (\is_int($after)) {
            $hits = array_values(array_filter($hits, static fn (array $h) => $h['sort'][1] > $after));
        }

        $hits = \array_slice($hits, 0, (int) ($body['size'] ?? 10));

        return ['pit_id' => $pitId, 'hits' => ['total' => ['value' => \count($snapshot)], 'hits' => $hits]];
    }

    public function closePointInTime(string $pitId): void
    {
        $this->maybeFail();

        if (isset($this->pointsInTime[$pitId])) {
            $this->pointsInTime[$pitId]['closed'] = true;
        }
    }

    public function indexExists(string $index): bool
    {
        $this->maybeFail();

        return isset($this->indices[$index]);
    }

    public function createIndex(string $index, array $definition): void
    {
        $this->maybeFail();
        $this->indices[$index] = $definition;
    }

    public function mapping(string $index): array
    {
        $this->maybeFail(__FUNCTION__);

        if (!isset($this->indices[$index])) {
            throw IndexNotFoundException::forIndex($index);
        }

        return $this->indices[$index]['mappings']['properties'] ?? [];
    }

    public function info(): array
    {
        $this->maybeFail();

        return ['name' => 'in-memory', 'version' => ['number' => '0.0.0']];
    }

    /**
     * @return array<string, mixed>
     */
    public function only(string $index): array
    {
        \assert(\count($this->documents[$index] ?? []) === 1, sprintf('Expected exactly one document in "%s".', $index));

        return $this->documents[$index][0];
    }

    private function maybeFail(?string $method = null): void
    {
        if ($method !== null && isset($this->failOn[$method])) {
            throw $this->failOn[$method] instanceof AuditException ? $this->failOn[$method] : TransportUnavailableException::because($this->failOn[$method]);
        }

        if ($this->failWith !== null) {
            throw $this->failWith instanceof TransportUnavailableException ? $this->failWith : TransportUnavailableException::because($this->failWith);
        }
    }
}
