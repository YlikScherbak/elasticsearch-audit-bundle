<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;

/**
 * A transport that can carry many documents in one go.
 *
 * A flush that touched fifty entities produces fifty records; sending them one
 * request at a time is fifty round-trips in the tail of the web request. A
 * transport implementing this gets them all at once — one _bulk call, or one
 * message — and reports what did not make it, per position.
 *
 * Separate from TransportInterface on purpose: a custom transport that only
 * knows send() keeps working, the writer falls back to sending one by one.
 *
 * Every item carries an id, and the writer assigns one before anything is sent: a
 * batch holding a transient failure is re-sent whole, and a document without an id
 * would be stored a second time under a new one — the same audit event twice.
 *
 * @phpstan-type BatchItem array{index: string, document: array<string, mixed>, id: string}
 */
interface BatchTransportInterface extends TransportInterface
{
    /**
     * @param list<BatchItem> $items
     *
     * @return BulkResult per-position failures; an asynchronous transport reports none here,
     *                    since it cannot know yet — its worker deals with them
     *
     * @throws TransportUnavailableException the whole batch could not be sent
     */
    public function sendMany(array $items): BulkResult;
}
