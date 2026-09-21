<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * Where the application adds what only it knows.
 *
 * A record carries the generic facts (type, id, event, actor, changes). Anything
 * you want to filter the history by later — the sales channel of an order, the
 * warehouse of a stock movement, the tenant — is a denormalised attribute the
 * enricher adds at write time, together with its mapping so `audit:index:create`
 * knows the field type.
 *
 * An enricher runs when the record is written. For a fact about the moment the change
 * happened — the route, the request id — that is the same instant almost always and the
 * wrong one exactly when it matters: a flush whose publishing was swallowed is written
 * by a later one, and a later one belongs to another request. {@see MomentEnricherInterface}
 * is asked before the records exist and travels with them.
 *
 * Implementations are picked up automatically (the interface is autoconfigured).
 */
interface AuditEnricherInterface extends DeclaresAuditFieldsInterface
{
    public function supports(AuditRecord $record): bool;

    /**
     * Return the record with attributes (or changes) added. Must not throw for
     * records it does not support; the writer only calls it after supports().
     */
    public function enrich(AuditRecord $record): AuditRecord;
}
