<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\AuditException;
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
 * A write goes only to an index this gateway has seen exist. Elasticsearch would
 * otherwise create it on the fly with a guessed mapping — loggedAt as text
 * (unsortable, so every read fails), changes indexed field by field (mapping
 * explosion, and later documents rejected over type conflicts) — and audit:check
 * could not tell. The existence check costs one HEAD request per index per process;
 * its answer is remembered, and forgotten again the moment a write answers 404.
 *
 * It is a good error rather than a guarantee, and the difference is worth being
 * precise about: between the HEAD and the write the index can be dropped or rolled
 * over, and no amount of checking here can close that window. The guarantee belongs
 * to the cluster — action.auto_create_index excluding the audit pattern, or
 * allow_auto_create: false on an index template that matches it — which the README
 * asks for as part of installing the bundle. This check makes the common mistake
 * legible; that setting makes the guessed mapping impossible.
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

        // Elasticsearch echoes the offending document back in a parsing error unless
        // told not to, and an audit document is exactly the one whose values must not
        // travel into an error message, a log or an exception. Stripping the preview
        // afterwards is a second line; this is the first.
        $params = ['index' => $index, 'body' => $document, 'include_source_on_error' => false];

        if ($id !== null) {
            $params['id'] = $id;
        }

        if ($refresh) {
            $params['refresh'] = 'true';
        }

        try {
            // The response body is not read, but answer() still guards it: an
            // asynchronous client returns a promise nobody here waits on, and dropping
            // it would report a write that may never have happened as success.
            $this->call(fn () => self::answer($this->client->index($params)), $index);
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

        foreach ($items as $position => $item) {
            // Without an id Elasticsearch generates one, and a batch re-sent after a
            // transient failure would store every already-written document a second
            // time under a new id. The writer assigns an id before anything is sent;
            // this is the boundary that keeps that true for every caller.
            if (($item['id'] ?? '') === '') {
                throw new \InvalidArgumentException(sprintf('The document at position %d has no id. A bulk batch is re-sent whole when the cluster asks for it again, so every document needs an id of its own to overwrite itself instead of arriving twice.', $position));
            }

            $body[] = ['index' => ['_index' => $item['index'], '_id' => $item['id']]];
            $body[] = $item['document'];
        }

        $response = $this->call(fn () => self::answer($this->client->bulk(['body' => $body, 'include_source_on_error' => false]))->asArray());
        $result = BulkResult::fromResponse($response, \count($items));

        // The same forgetting index() does on its 404: an index that answered "not
        // found" per item is gone, and a long-lived worker's cache must not keep
        // skipping the existence check until a restart.
        foreach ($result->failures as $position => $failure) {
            if ($failure['status'] === 404) {
                unset($this->known[$items[$position]['index']]);
            }
        }

        return $result;
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
            // The view expired between two batches. Recognised by Elasticsearch's own
            // error type where the response carries one — the human-readable reason is
            // a message, and a message is free to change — with the text as a fallback
            // for a response shaped otherwise. The cluster's words do not say what to
            // do about it; the setting does.
            if (self::isMissingSearchContext($e) || str_contains($e->getMessage(), 'search context')) {
                throw new InvalidQueryException(sprintf('%s — the point in time expired between two batches (keep-alive %s): raise reader.point_in_time_keep_alive above the time a consumer needs for one batch, or iterate with consistent: false.', $e->getMessage(), $keepAlive), $e->getCode(), $e);
            }

            throw $e;
        }
    }

    public function closePointInTime(string $pitId): void
    {
        try {
            $this->call(fn () => self::answer($this->client->closePointInTime(['body' => ['id' => $pitId]])));
        } catch (RequestRejectedException $e) {
            // Already expired or unknown: the cluster answers 404, and there is nothing to
            // release. Anything else — no permission, for one — means the view is still
            // open and holding memory, which is not something to pass over in silence.
            if ($e->getCode() !== 404) {
                throw $e;
            }
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
        $this->call(fn () => self::answer($this->client->indices()->create(['index' => $index, 'body' => $definition])));
        $this->known[$index] = true;
    }

    public function mapping(string $index): array
    {
        $response = $this->call(fn () => self::answer($this->client->indices()->getMapping(['index' => $index]))->asArray(), $index);

        // The response is keyed by concrete index name, and an alias can stand for
        // several of them. A field counts as mapped only where every one of them maps it
        // the same way: audit:check exists to catch an index that was left behind, and
        // reading whichever came first would have hidden exactly that.
        $shared = null;

        foreach ($response as $concrete) {
            $properties = \is_array($concrete) && \is_array($concrete['mappings']['properties'] ?? null)
                ? $concrete['mappings']['properties']
                : [];

            if ($shared === null) {
                $shared = $properties;

                continue;
            }

            foreach ($shared as $field => $definition) {
                // Compared by content, not by the order the keys happen to be in: a
                // mapping object is unordered to Elasticsearch, and two indices behind
                // one alias — one created from a template, one grown by putMapping —
                // can spell the same mapping differently. Order-sensitive comparison
                // called those incompatible and dropped a field that was perfectly fine.
                if (!\array_key_exists($field, $properties) || !self::sameMapping($properties[$field], $definition)) {
                    unset($shared[$field]);
                }
            }
        }

        return $shared ?? [];
    }

    /**
     * Whether the cluster refused because the point in time is gone, by the type it
     * names rather than by the sentence it wrote.
     */
    private static function isMissingSearchContext(InvalidQueryException $e): bool
    {
        $previous = $e->getPrevious();

        if (!$previous instanceof ClientResponseException) {
            return false;
        }

        try {
            $body = json_decode((string) $previous->getResponse()->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!\is_array($body)) {
            return false;
        }

        foreach ([$body['error']['type'] ?? null, $body['error']['root_cause'][0]['type'] ?? null] as $type) {
            if ($type === 'search_context_missing_exception') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether two mapping fragments say the same thing, whatever order they say it in.
     */
    private static function sameMapping(mixed $one, mixed $other): bool
    {
        if (\is_array($one) && \is_array($other)) {
            ksort($one);
            ksort($other);

            if (array_keys($one) !== array_keys($other)) {
                return false;
            }

            foreach ($one as $key => $value) {
                if (!self::sameMapping($value, $other[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $one === $other;
    }

    public function putMapping(string $index, array $properties): void
    {
        $this->call(fn () => self::answer($this->client->indices()->putMapping(['index' => $index, 'body' => ['properties' => $properties]])), $index);
    }

    public function settings(string $index): array
    {
        $response = $this->call(fn () => self::answer($this->client->indices()->getSettings(['index' => $index]))->asArray(), $index);
        $settings = [];

        foreach ($response as $concrete => $data) {
            $settings[$concrete] = \is_array($data['settings']['index'] ?? null) ? $data['settings']['index'] : [];
        }

        return $settings;
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

            // 429 is the cluster asking for the same request in a moment, not refusing it.
            // Classified with the unreachable cluster because that is the class the bundle
            // retries: an audit record must not be dropped for arriving during a busy hour.
            if ($status === 429) {
                throw TransportUnavailableException::because($e);
            }

            if ($status >= 400 && $status < 500) {
                $reason = self::reason($e);

                throw $query
                    ? new InvalidQueryException('Elasticsearch rejected the query: '.self::actionable($reason), $status, $e)
                    : RequestRejectedException::because($status, $reason, $e);
            }

            throw TransportUnavailableException::because($e);
        } catch (AuditException $e) {
            // Raised inside the closure by the bundle itself — a client built for
            // asynchronous responses, say. It already says what is wrong; wrapping it as
            // an unreachable cluster would send whoever reads it to the network.
            throw $e;
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
