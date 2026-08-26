<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * GatewayInterface over the official client. The same calls work on the 8.x and
 * 9.x clients, which is why this is the only class that touches the client at all.
 */
final class ElasticsearchGateway implements GatewayInterface
{
    public function __construct(private readonly Client $client)
    {
    }

    public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
    {
        $params = ['index' => $index, 'body' => $document];

        if ($id !== null) {
            $params['id'] = $id;
        }

        if ($refresh) {
            $params['refresh'] = 'true';
        }

        $this->call(fn () => $this->client->index($params), $index);
    }

    public function search(string $index, array $body): array
    {
        return $this->call(fn () => $this->client->search(['index' => $index, 'body' => $body])->asArray(), $index);
    }

    public function indexExists(string $index): bool
    {
        return $this->call(fn () => $this->client->indices()->exists(['index' => $index])->asBool());
    }

    public function createIndex(string $index, array $definition): void
    {
        $this->call(fn () => $this->client->indices()->create(['index' => $index, 'body' => $definition]));
    }

    public function mapping(string $index): array
    {
        $response = $this->call(fn () => $this->client->indices()->getMapping(['index' => $index])->asArray(), $index);

        // The response is keyed by the concrete index name, which differs from $index when it is an alias.
        $first = reset($response);

        return \is_array($first) ? ($first['mappings']['properties'] ?? []) : [];
    }

    public function info(): array
    {
        return $this->call(fn () => $this->client->info()->asArray());
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function call(callable $call, ?string $index = null): mixed
    {
        try {
            return $call();
        } catch (ClientResponseException $e) {
            if ($index !== null && $e->getResponse()->getStatusCode() === 404) {
                throw IndexNotFoundException::forIndex($index, $e);
            }

            throw TransportUnavailableException::because($e);
        } catch (\Throwable $e) {
            throw TransportUnavailableException::because($e);
        }
    }
}
