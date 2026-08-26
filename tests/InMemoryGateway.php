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

    public ?\Throwable $failWith = null;

    public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
    {
        $this->maybeFail();
        $this->documents[$index][] = $document;
    }

    public function search(string $index, array $body): array
    {
        $this->maybeFail();

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
        $this->maybeFail();

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

    private function maybeFail(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith instanceof TransportUnavailableException ? $this->failWith : TransportUnavailableException::because($this->failWith);
        }
    }
}
