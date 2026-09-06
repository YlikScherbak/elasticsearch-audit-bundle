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
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Borsche\ElasticsearchAuditBundle\Writer\SystemClock;

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

    /**
     * @param bool|null $sourceOnError what the cluster should do with a document it
     *                                 refuses: false asks it to keep the document out of
     *                                 the error, true leaves its own default alone, and
     *                                 null decides from its version
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?bool $sourceOnError = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
    }

    private readonly ClockInterface $clock;
    private readonly LoggerInterface $logger;

    /**
     * How long a "this cluster does not know it" is believed for.
     *
     * A positive answer is kept for the life of the process: a cluster that knows the
     * parameter is not going to stop. A negative one has to expire, and the reason is
     * the process it lives in. A command runs for seconds; `messenger:consume` runs for
     * weeks. A worker that met one 8.17 node during a rolling upgrade would go on
     * writing without the parameter long after the upgrade finished — the first line
     * off, until somebody restarted it, and nowhere saying so.
     *
     * The cost of being wrong the other way is one write per window that is refused and
     * immediately sent again. On a cluster that really is old, that is the price of not
     * having said so in the configuration — which is what client.include_source_on_error
     * is for, and what the warning below points at.
     */
    private const RETRY_THE_PARAMETER_AFTER = 300;

    /** Whether this cluster knows the parameter; asked once, on the first write. */
    private ?bool $clusterKnowsIt = null;

    /** When a negative answer was reached, so it can be asked again later. */
    private ?\DateTimeImmutable $decidedAt = null;

    /** The reason last given for not sending it, so an unchanged one is not announced twice. */
    private ?string $stoppedBecause = null;

    /**
     * Whether a write carries `include_source_on_error=false`.
     *
     * Elasticsearch echoes the offending document back in a parsing error unless told
     * not to, and an audit document is exactly the one whose values must not travel
     * into an error message, a log or an exception. The parameter is the first line
     * against that; the second is that a refused document is described structurally
     * (see DocumentRefusal) and, under the default redact.failure_details, the
     * cluster's own exception is not carried along at all.
     *
     * Which is why this is a question rather than a constant. The parameter has existed
     * since 8.18, and an unknown query parameter is a 400 — so sending it unconditionally
     * made every write fail on an older cluster, for a first line whose second line was
     * holding anyway. The version is asked for once per process and remembered: clusters
     * do not move between operations, and a rolling upgrade is answered by the next
     * process.
     */
    private function suppressesSource(): bool
    {
        if ($this->sourceOnError !== null) {
            return $this->sourceOnError === false;
        }

        if ($this->clusterKnowsIt === true) {
            return true;
        }

        if ($this->clusterKnowsIt === false && !$this->staleDecision()) {
            return false;
        }

        try {
            $info = $this->info();
        } catch (AuditException) {
            // Not remembered: a cluster that could not be reached has not answered the
            // question, and the write about to happen will fail on its own terms anyway.
            // Until one does answer, keep the protection rather than drop it.
            return true;
        }

        $version = \is_array($info['version'] ?? null) && \is_string($info['version']['number'] ?? null)
            ? $info['version']['number']
            : '';

        if (ClusterVersion::knowsIncludeSourceOnError($version)) {
            if ($this->stoppedBecause !== null) {
                // The other bracket. Something changed for the better — an upgrade
                // finished, a proxy was fixed — and the line that said the guarantee had
                // gone quiet deserves the line that says it is back.
                $this->logger->info('Audit writes to Elasticsearch carry include_source_on_error again: the cluster reports version {version}.', ['version' => $version]);
            }

            $this->clusterKnowsIt = true;
            $this->decidedAt = null;
            $this->stoppedBecause = null;

            return true;
        }

        $this->stopSending(sprintf('it reports version %s, and the parameter has existed since %d.%d', $version === '' ? '?' : $version, self::MINIMUM_VERSION[0], self::MINIMUM_VERSION[1]));

        return false;
    }

    /**
     * Remembers that this cluster does not take the parameter, and says so.
     *
     * Out loud, because what it turns off is a guarantee. The bundle's second line still
     * holds — a refused document is described by error type and field name, and under
     * the default redact.failure_details the cluster's own exception does not travel —
     * but the first line going quiet is exactly the kind of thing that should not happen
     * without a line in the log naming the moment.
     */
    private function stopSending(string $because): void
    {
        // Once per answer, not once per window. The window is how often to ask, and the
        // two are separate decisions: on a cluster that is never going to be upgraded —
        // and those exist, not everybody can move — the same warning every five minutes
        // is 288 a day per worker about a fact that has not changed. That reads as an
        // outage in progress, and a log nobody can bear to read is where a real warning
        // standing next to it goes unseen. A changed answer is an event again, and says
        // so at the same level.
        $repeat = $this->stoppedBecause === $because;

        $this->clusterKnowsIt = false;
        $this->decidedAt = $this->clock->now();
        $this->stoppedBecause = $because;

        if ($repeat) {
            $this->logger->debug('Audit writes to Elasticsearch still do not carry include_source_on_error: {because}.', ['because' => $because]);

            return;
        }

        $this->logger->warning('Audit writes to Elasticsearch will not carry include_source_on_error: {because}. A document this cluster refuses is quoted back in its own error; the bundle does not repeat it, and redact.failure_details: "cause" keeps it out of what is logged and dispatched. This is asked again in {seconds}s, so a cluster that is upgraded starts getting the parameter without a restart. Set client.include_source_on_error to stop asking.', [
            'because' => $because,
            'seconds' => self::RETRY_THE_PARAMETER_AFTER,
        ]);
    }

    private function staleDecision(): bool
    {
        return $this->decidedAt === null
            || $this->clock->now()->getTimestamp() - $this->decidedAt->getTimestamp() >= self::RETRY_THE_PARAMETER_AFTER;
    }

    /**
     * Runs a write, and runs it once more without the parameter if that is what the
     * cluster refused.
     *
     * Version detection asks one node and believes it about the cluster, which is true
     * of a cluster that is not being upgraded. During a rolling 8.17 to 8.18 the node
     * that answers info() can be the new one while the next write lands on an old one:
     * a 400, and under on_failure: log a record dropped, for the whole length of the
     * upgrade. So the refusal is read rather than assumed. Nothing was written — that
     * is what a 400 for an unknown query parameter means — so sending it again is a
     * retry and not a second document.
     *
     * The same net catches a proxy or a hosted offering that rejects the parameter
     * whatever the version behind it says, which no amount of version reading would.
     *
     * @template T
     *
     * @param callable(bool): T $write given whether to carry the parameter
     *
     * @return T
     */
    private function write(callable $write, ?string $index = null): mixed
    {
        $suppressing = $this->suppressesSource();

        try {
            return $this->call(fn () => $write($suppressing), $index);
        } catch (RequestRejectedException $e) {
            // Only where the bundle chose: a deployment that set include_source_on_error
            // explicitly has said which cluster it is talking to, and quietly doing the
            // other thing would be an answer to a question it already answered.
            if (!$suppressing || $this->sourceOnError !== null || !self::refusedTheParameter($e->getPrevious())) {
                throw $e;
            }

            $this->stopSending('a node refused it, whatever version the cluster reports');

            return $this->call(fn () => $write(false), $index);
        }
    }

    /**
     * Whether this refusal is about the parameter rather than about the document.
     *
     * Read from the cluster's own wording, which is the only place it is said — and
     * read narrowly: a status, a phrase and the parameter's name. A wording this does
     * not recognise costs a retry that would have worked, which is where the bundle
     * already was, and never mistakes a refused document for a refused parameter.
     */
    private static function refusedTheParameter(?\Throwable $e): bool
    {
        if (!$e instanceof ClientResponseException || $e->getResponse()->getStatusCode() !== 400) {
            return false;
        }

        $body = (string) $e->getResponse()->getBody();

        return str_contains($body, 'include_source_on_error') && str_contains($body, 'unrecognized parameter');
    }

    public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
    {
        if (!isset($this->known[$index]) && !$this->indexExists($index)) {
            throw IndexNotFoundException::forIndex($index);
        }

        try {
            // The response body is not read, but answer() still guards it: an
            // asynchronous client returns a promise nobody here waits on, and dropping
            // it would report a write that may never have happened as success.
            $this->write(function (bool $suppressing) use ($index, $document, $id, $refresh): mixed {
                $params = ['index' => $index, 'body' => $document];

                if ($suppressing) {
                    $params['include_source_on_error'] = false;
                }

                if ($id !== null) {
                    $params['id'] = $id;
                }

                if ($refresh) {
                    $params['refresh'] = 'true';
                }

                return self::answer($this->client->index($params));
            }, $index);
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

        $body = [];

        // Before the existence check, which is a round trip: a document without an id is
        // a programming error in the caller and does not depend on anything the cluster
        // has to say. Without an id Elasticsearch generates one, and a batch re-sent
        // after a transient failure would store every already-written document a second
        // time under a new id. The writer assigns an id before anything is sent; this is
        // the boundary that keeps that true for every caller.
        foreach ($items as $position => $item) {
            if (($item['id'] ?? '') === '') {
                throw new \InvalidArgumentException(sprintf('The document at position %d has no id. A bulk batch is re-sent whole when the cluster asks for it again, so every document needs an id of its own to overwrite itself instead of arriving twice.', $position));
            }

            $body[] = ['index' => ['_index' => $item['index'], '_id' => $item['id']]];
            $body[] = $item['document'];
        }

        // The same guarantee as index(): no index is created by a write with a guessed mapping.
        foreach (array_unique(array_column($items, 'index')) as $index) {
            if (!isset($this->known[$index]) && !$this->indexExists($index)) {
                throw IndexNotFoundException::forIndex($index);
            }
        }

        // A bulk request refused whole is refused before anything in it was written, so
        // this retry is the same request rather than a second copy of its documents.
        // Per-item refusals are not this: they come back inside a 200 and are read by
        // BulkResult.
        $response = $this->write(function (bool $suppressing) use ($body): array {
            $params = ['body' => $body];

            if ($suppressing) {
                $params['include_source_on_error'] = false;
            }

            return self::answer($this->client->bulk($params))->asArray();
        });
        $result = BulkResult::fromResponse($response, \count($items), array_map(strval(...), array_column($items, 'id')));

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
            throw TransportUnavailableException::saying('Elasticsearch opened a point in time but returned no id.');
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
        // The status has to be read here rather than left to call(). The client
        // suppresses its own exception for HEAD requests, so nothing is thrown for it to
        // classify, and asBool() is a plain "2xx" — under which a role without
        // view_index_metadata (403), a name the cluster rejects (400) and an unhealthy
        // cluster (5xx) all became "the index does not exist", and the bundle sent the
        // operator off to create an index that is already there.
        $response = $this->call(fn () => self::answer($this->client->indices()->exists(['index' => $index])));
        $status = $response->getStatusCode();

        if ($status === 404) {
            return false;
        }

        if ($status < 200 || $status >= 300) {
            throw self::whatTheStatusMeans($status, $response, $index);
        }

        $this->known[$index] = true;

        return true;
    }

    /**
     * The classification call() makes, for an answer that arrived instead of an
     * exception. Same order, same reasons: backpressure and an unhealthy cluster are
     * asked again, anything else in the 4xx range is a refusal.
     */
    private static function whatTheStatusMeans(int $status, Elasticsearch $response, string $index): AuditException
    {
        $body = json_decode((string) $response->getBody(), true);
        $reason = \is_array($body) ? ($body['error']['root_cause'][0]['reason'] ?? $body['error']['reason'] ?? null) : null;
        $reason = \is_string($reason) && $reason !== ''
            ? RequestRejectedException::withoutValuePreview($reason)
            : sprintf('Elasticsearch answered HTTP %d for "%s" without a reason anyone can read.', $status, $index);

        if ($status === 429 || $status >= 500) {
            return TransportUnavailableException::saying($reason);
        }

        return RequestRejectedException::because($status, $reason);
    }

    public function createIndex(string $index, array $definition): void
    {
        $this->call(fn () => self::answer($this->client->indices()->create(['index' => $index, 'body' => $definition])));
        $this->known[$index] = true;
    }

    public function indicesAcceptingUnknownFields(string $index): array
    {
        $response = $this->call(fn () => self::answer($this->client->indices()->getMapping(['index' => $index]))->asArray(), $index);
        $open = [];

        foreach ($response as $concrete => $mappings) {
            // Absent means Elasticsearch's own default, which is dynamic: true — the
            // guessed mapping this bundle exists to keep out. The value comes back as a
            // bool or as a string depending on how it was set, so it is read as text.
            $dynamic = \is_array($mappings) ? ($mappings['mappings']['dynamic'] ?? true) : true;
            $dynamic = \is_bool($dynamic) ? ($dynamic ? 'true' : 'false') : (string) $dynamic;

            if ($dynamic !== 'false' && $dynamic !== 'strict') {
                $open[] = (string) $concrete;
            }
        }

        return $open;
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
                $reason = self::reason($e, aboutADocument: !$query);

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
     * What went wrong, said in Elasticsearch's fields rather than in its prose.
     *
     * The reason text is written for a person to read and is free to change between
     * versions and parsers. It quotes a refused value — as `Preview of field's value:
     * '…'`, which this used to cut with a regex, and just as easily as `received value
     * [ … ]` or `cannot convert "…" to long`. A guarantee that depends on the cluster
     * keeping one wording is not a guarantee; `type` is machine-readable and says what
     * went wrong without saying what with.
     *
     * Nothing is lost by it: the full text stays on the previous exception, where
     * redact.failure_details decides whether it travels any further. And never
     * $e->getMessage() — the client builds that as "<status> <phrase>: <the whole
     * response body>".
     */
    private static function reason(ClientResponseException $e, bool $aboutADocument): string
    {
        $status = $e->getResponse()->getStatusCode();

        try {
            $body = json_decode((string) $e->getResponse()->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return sprintf('Elasticsearch answered HTTP %d with something that is not JSON.', $status);
        }

        $type = \is_array($body) ? ($body['error']['root_cause'][0]['type'] ?? $body['error']['type'] ?? null) : null;
        $reason = \is_array($body) ? ($body['error']['root_cause'][0]['reason'] ?? $body['error']['reason'] ?? null) : null;

        // Only a refusal of a *document* has the cluster quoting a value, and that is
        // the one place the prose is dropped. A refused search saw no audited value —
        // the bundle built the query — and its wording is the whole diagnostic there:
        // "Result window is too large", "No search context found for id". Throwing that
        // away would cost an operator the answer and protect nothing.
        if (!$aboutADocument && \is_string($reason) && $reason !== '') {
            return RequestRejectedException::withoutValuePreview($reason);
        }

        // Without the status: the callers put that in front of this. The same words a
        // refused bulk item gets, from the same place — the two paths described one
        // refusal differently until now, and the weaker description was the boundary.
        return DocumentRefusal::describe($type, $reason) ?? 'the cluster gave no error type anyone can read';
    }
}
