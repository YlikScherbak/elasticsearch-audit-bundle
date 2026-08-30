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

    public function bulk(array $items): BulkResult
    {
        if ($items === []) {
            return BulkResult::empty();
        }

        // The same guarantee as index(): no index is created by a write with a guessed mapping.
        foreach (array_unique(array_column($items, 'index')) as $index) {
            if (!isset($this->known[$index]) && !$this->indexExists($index)) {
                throw IndexNotFoundException::forIndex($index);
            }
        }

        $body = [];

        foreach ($items as $item) {
            $action = ['_index' => $item['index']];

            if ($item['id'] !== null) {
                $action['_id'] = $item['id'];
            }

            $body[] = ['index' => $action];
            $body[] = $item['document'];
        }

        $response = $this->call(fn () => self::answer($this->client->bulk(['body' => $body]))->asArray());

        return BulkResult::fromResponse($response, \count($items));
    }

    public function openPointInTime(string $index, string $keepAlive): string
    {
        $response = $this->call(fn () => self::answer($this->client->openPointInTime(['index' => $index, 'keep_alive' => $keepAlive]))->asArray(), $index);
        $id = $response['id'] ?? null;

        if (!\is_string($id) || $id === '') {
            throw TransportUnavailableException::because(new \UnexpectedValueException('Elasticsearch opened a point in time but returned no id.'));
        }

        return $id;
    }

    public function searchPointInTime(string $pitId, string $keepAlive, array $body): array
    {
        $body['pit'] = ['id' => $pitId, 'keep_alive' => $keepAlive];

        try {
            return $this->call(fn () => self::answer($this->client->search(['body' => $body]))->asArray(), query: true);
        } catch (InvalidQueryException $e) {
            // "No search context found": the view expired between two batches. The cluster's
            // message does not say what to do about it; the setting does.
            if (str_contains($e->getMessage(), 'search context')) {
                throw new InvalidQueryException(sprintf('%s — the point in time expired between two batches (keep-alive %s): raise reader.point_in_time_keep_alive above the time a consumer needs for one batch, or iterate with consistent: false.', $e->getMessage(), $keepAlive), $e->getCode(), $e);
            }

            throw $e;
        }
    }

    public function closePointInTime(string $pitId): void
    {
        try {
            $this->call(fn () => $this->client->closePointInTime(['body' => ['id' => $pitId]]));
        } catch (RequestRejectedException) {
            // Already expired or unknown: the cluster answers 404, and there is nothing to release.
        }
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
                    ? new InvalidQueryException('Elasticsearch rejected the query: '.self::actionable($reason), $status, $e)
                    : RequestRejectedException::because($status, $reason, $e);
            }

            throw TransportUnavailableException::because($e);
        } catch (\Throwable $e) {
            throw TransportUnavailableException::because($e);
        }
    }

    /**
     * Elasticsearch's own words, and what to do about them when the answer is not in the
     * query but in the cluster. reader.max_result_window is checked before the request;
     * the index's own window is not, and an index created before the setting was raised
     * (or on a contour where nobody raised it) refuses the page the reader allowed.
     */
    private static function actionable(string $reason): string
    {
        if (!str_contains($reason, 'Result window is too large')) {
            return $reason;
        }

        return $reason.' — index.max_result_window on this index is lower than reader.max_result_window: raise it on the index, lower the setting to match, or page with a cursor, which has no ceiling.';
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

        return \is_string($reason) && $reason !== '' ? RequestRejectedException::withoutValuePreview($reason) : $e->getMessage();
    }
}
