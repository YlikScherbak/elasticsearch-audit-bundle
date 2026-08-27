<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;

/**
 * GatewayInterface over the official client. The same calls work on the 8.x and
 * 9.x clients, which is why this is the only class that touches the client at all.
 *
 * A write goes only to an index that exists. Elasticsearch would otherwise create
 * it on the fly with a guessed mapping — loggedAt as text (unsortable, so every read
 * fails), changes indexed field by field (mapping explosion, and later documents
 * rejected over type conflicts) — and audit:check could not tell. The existence
 * check costs one HEAD request per index per process; its answer is remembered.
 */
final class ElasticsearchGateway implements GatewayInterface
{
    /** @var array<string, true> indices known to exist */
    private array $known = [];

    public function __construct(private readonly Client $client)
    {
    }

    public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
    {
        if (!isset($this->known[$index]) && !$this->indexExists($index)) {
            throw IndexNotFoundException::forIndex($index);
        }

        $params = ['index' => $index, 'body' => $document];

        if ($id !== null) {
            $params['id'] = $id;
        }

        if ($refresh) {
            $params['refresh'] = 'true';
        }

        try {
            $this->call(fn () => $this->client->index($params), $index);
        } catch (IndexNotFoundException $e) {
            // The index went away since we last saw it (dropped under a long-running
            // worker): forget it, so the next write checks again instead of trusting
            // a stale answer.
            unset($this->known[$index]);

            throw $e;
        }
    }

    public function search(string $index, array $body): array
    {
        return $this->call(fn () => self::answer($this->client->search(['index' => $index, 'body' => $body]))->asArray(), $index, query: true);
    }

    public function indexExists(string $index): bool
    {
        $exists = $this->call(fn () => self::answer($this->client->indices()->exists(['index' => $index]))->asBool());

        if ($exists) {
            $this->known[$index] = true;
        }

        return $exists;
    }

    public function createIndex(string $index, array $definition): void
    {
        $this->call(fn () => $this->client->indices()->create(['index' => $index, 'body' => $definition]));
        $this->known[$index] = true;
    }

    public function mapping(string $index): array
    {
        $response = $this->call(fn () => self::answer($this->client->indices()->getMapping(['index' => $index]))->asArray(), $index);

        // The response is keyed by the concrete index name, which differs from $index when it is an alias.
        $first = reset($response);

        return \is_array($first) ? ($first['mappings']['properties'] ?? []) : [];
    }

    public function info(): array
    {
        return $this->call(fn () => self::answer($this->client->info())->asArray());
    }

    /**
     * The client answers with a promise instead of a response when it is built for
     * asynchronous use, which this bundle does not support: every call here needs its
     * answer before it can go on.
     */
    private static function answer(object $response): Elasticsearch
    {
        if (!$response instanceof Elasticsearch) {
            throw new NotConfiguredException(sprintf('The Elasticsearch client answered with a %s: it is built for asynchronous responses, and the audit bundle needs a synchronous client.', get_debug_type($response)));
        }

        return $response;
    }

    /**
     * Maps what the client throws onto the bundle's exceptions: 404 on a named index is
     * a missing index; any other 4xx is a request Elasticsearch refused (a bad query when
     * $query, a rejected document otherwise) — not an unreachable cluster, which is what
     * everything else (connection errors, 5xx) becomes.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function call(callable $call, ?string $index = null, bool $query = false): mixed
    {
        try {
            return $call();
        } catch (ClientResponseException $e) {
            $status = $e->getResponse()->getStatusCode();

            if ($index !== null && $status === 404) {
                throw IndexNotFoundException::forIndex($index, $e);
            }

            if ($status >= 400 && $status < 500) {
                $reason = self::reason($e);

                throw $query
                    ? new InvalidQueryException('Elasticsearch rejected the query: '.$reason, $status, $e)
                    : RequestRejectedException::because($status, $reason, $e);
            }

            throw TransportUnavailableException::because($e);
        } catch (\Throwable $e) {
            throw TransportUnavailableException::because($e);
        }
    }

    /**
     * The "reason" Elasticsearch gives in its error body, falling back to the client's message.
     */
    private static function reason(ClientResponseException $e): string
    {
        try {
            $body = json_decode((string) $e->getResponse()->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $e->getMessage();
        }

        $reason = \is_array($body) ? ($body['error']['root_cause'][0]['reason'] ?? $body['error']['reason'] ?? null) : null;

        return \is_string($reason) && $reason !== '' ? $reason : $e->getMessage();
    }
}
