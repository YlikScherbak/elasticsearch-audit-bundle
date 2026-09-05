<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\NotConfiguredException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;

/**
 * The handful of Elasticsearch calls the bundle needs, behind one interface.
 *
 * Keeps the client version (8 or 9) out of the rest of the code and gives the
 * tests an in-memory implementation to assert against.
 *
 * Two failures are common to every method here and are not repeated on each:
 * {@see TransportUnavailableException} when the cluster cannot be reached or answers
 * something unusable (a 5xx, a 429 — backpressure counts as "ask again", not as a
 * refusal — a response that cannot be read), and {@see NotConfiguredException} when
 * the client was built for asynchronous responses, which this bundle does not
 * support. What each method lists below is what is specific to it.
 */
interface GatewayInterface
{
    /**
     * Stores one document. The index has to exist already: a write must never let
     * Elasticsearch create the index with a guessed mapping (see audit:index:create).
     *
     * @param array<string, mixed> $document
     *
     * @throws IndexNotFoundException   the index does not exist — nothing was written
     * @throws RequestRejectedException Elasticsearch refused the document (it does not fit the mapping, say)
     */
    public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void;

    /**
     * @param array<string, mixed> $body the request body (query, sort, from, size, search_after...)
     *
     * @return array<string, mixed> the raw response
     *
     * @throws IndexNotFoundException the index does not exist
     * @throws InvalidQueryException  Elasticsearch rejected the request body (a stale cursor, an unmapped sort)
     */
    public function search(string $index, array $body): array;

    /**
     * Stores many documents in one _bulk request. Every index involved has to exist,
     * for the same reason as with index(). Elasticsearch judges each document on its
     * own, so the result names the positions that were refused; the request as a
     * whole failing is an exception.
     *
     * Every item carries its own id, and that is a requirement rather than a
     * convenience: a batch holding a transient failure is re-sent whole, and a document
     * without an id would be stored a second time under a new one — the same audit
     * event twice, in the one place duplicates are indistinguishable from history.
     *
     * @param list<array{index: string, document: array<string, mixed>, id: string}> $items
     *
     * @throws \InvalidArgumentException an item has no id, so the batch cannot be retried safely
     * @throws IndexNotFoundException   one of the indices does not exist — nothing was written
     * @throws RequestRejectedException the cluster refused the request as a whole (the per-document
     *                                  refusals are in the result instead)
     */
    public function bulk(array $items): BulkResult;

    /**
     * Opens a point in time over the index: a frozen view that later searches read from,
     * so a long export sees neither the documents written after it started nor a
     * document twice. Costs the cluster resources until closed.
     *
     * @param string $keepAlive how long the view lives without being used, e.g. "1m"
     *
     * @return string the point-in-time id to search with and to close
     *
     * @throws IndexNotFoundException   the index does not exist
     * @throws RequestRejectedException the cluster refused (missing privilege, unsupported version)
     */
    public function openPointInTime(string $index, string $keepAlive): string;

    /**
     * Searches inside a point in time. The body is what search() takes, plus the pit
     * is added and the index comes from the pit — Elasticsearch refuses both at once.
     * Each search extends the view's life by $keepAlive.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> the raw response; "pit_id" in it may differ from the one sent and is the one to use next
     *
     * @throws InvalidQueryException the body was rejected — including the view having expired,
     *                               which the message names together with the setting that governs it
     */
    public function searchPointInTime(string $pitId, string $keepAlive, array $body): array;

    /**
     * Releases a point in time. Safe to call for one that already expired: a 404 is
     * swallowed, since there is nothing left to release.
     *
     * @throws RequestRejectedException the cluster refused for any other reason (a missing
     *                                  privilege), which leaves the view open and holding memory
     */
    public function closePointInTime(string $pitId): void;

    /**
     * @throws RequestRejectedException the name is not one Elasticsearch accepts
     */
    public function indexExists(string $index): bool;

    /**
     * @param array<string, mixed> $definition settings and mappings, as produced by IndexDefinition::toArray()
     *
     * @throws RequestRejectedException the index already exists, or the definition is one
     *                                  Elasticsearch refuses
     */
    public function createIndex(string $index, array $definition): void;

    /**
     * @return array<string, mixed> the "properties" of the index mapping
     *
     * @throws IndexNotFoundException the index does not exist
     */
    public function mapping(string $index): array;

    /**
     * Adds fields to an existing index's mapping. Additive by nature: Elasticsearch
     * itself refuses to change the type of a field that already exists, which is what
     * makes this safe to run against a live index — a changed type is a reindex, and
     * the cluster says so instead of quietly rewriting anything.
     *
     * @param array<string, array<string, mixed>> $properties field => mapping; an object
     *                                                        field may carry a partial
     *                                                        "properties" subtree, which
     *                                                        the cluster merges
     *
     * @throws IndexNotFoundException   the index does not exist
     * @throws RequestRejectedException a named field exists with another type
     */
    public function putMapping(string $index, array $properties): void;

    /**
     * The index settings, keyed by concrete index name — an alias can stand for
     * several, and a setting that must hold (max_result_window, say) has to hold on
     * every one of them. Values come back as Elasticsearch returns them, often
     * strings; a setting the index never set is simply absent.
     *
     * @return array<string, array<string, mixed>> concrete index => its "index" settings object
     *
     * @throws IndexNotFoundException the index does not exist
     */
    public function settings(string $index): array;

    /**
     * @return array<string, mixed> the cluster's root info response (name, version, ...)
     */
    public function info(): array;
}
