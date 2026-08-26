<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;

/**
 * The handful of Elasticsearch calls the bundle needs, behind one interface.
 *
 * Keeps the client version (8 or 9) out of the rest of the code and gives the
 * tests an in-memory implementation to assert against.
 */
interface GatewayInterface
{
    /**
     * Stores one document. The index has to exist already: a write must never let
     * Elasticsearch create the index with a guessed mapping (see audit:index:create).
     *
     * @param array<string, mixed> $document
     *
     * @throws IndexNotFoundException      the index does not exist — nothing was written
     * @throws RequestRejectedException    Elasticsearch refused the document (it does not fit the mapping, say)
     * @throws TransportUnavailableException
     */
    public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void;

    /**
     * @param array<string, mixed> $body the request body (query, sort, from, size, search_after...)
     *
     * @return array<string, mixed> the raw response
     *
     * @throws IndexNotFoundException
     * @throws InvalidQueryException       Elasticsearch rejected the request body (a stale cursor, an unmapped sort)
     * @throws TransportUnavailableException
     */
    public function search(string $index, array $body): array;

    /**
     * @throws TransportUnavailableException
     */
    public function indexExists(string $index): bool;

    /**
     * @param array<string, mixed> $definition settings and mappings, as produced by IndexDefinition::toArray()
     *
     * @throws TransportUnavailableException
     */
    public function createIndex(string $index, array $definition): void;

    /**
     * @return array<string, mixed> the "properties" of the index mapping
     *
     * @throws IndexNotFoundException
     * @throws TransportUnavailableException
     */
    public function mapping(string $index): array;

    /**
     * @return array<string, mixed> the cluster's root info response (name, version, ...)
     *
     * @throws TransportUnavailableException
     */
    public function info(): array;
}
