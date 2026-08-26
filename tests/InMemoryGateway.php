<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
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

    /** @var array<string, \Throwable> method name => what that method throws (wrapped as TransportUnavailableException) */
    public array $failOn = [];

    /** @var list<array{index: string, body: array<string, mixed>}> every search() call, for asserting on the request */
    public array $searches = [];

    /** @var (callable(string, array<string, mixed>): array<string, mixed>)|null scripted search responses */
    public $respondToSearch = null;

    /** @var (callable(string, array<string, mixed>): void)|null observes every index() call */
    public $onIndex = null;

    public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
    {
        $this->maybeFail();

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
            throw TransportUnavailableException::because($this->failOn[$method]);
        }

        if ($this->failWith !== null) {
            throw $this->failWith instanceof TransportUnavailableException ? $this->failWith : TransportUnavailableException::because($this->failWith);
        }
    }
}
