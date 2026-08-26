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
 * Implementations are picked up automatically (the interface is autoconfigured).
 */
interface AuditEnricherInterface
{
    public function supports(AuditRecord $record): bool;

    /**
     * Return the record with attributes (or changes) added. Must not throw for
     * records it does not support; the writer only calls it after supports().
     */
    public function enrich(AuditRecord $record): AuditRecord;

    /**
     * Mapping properties for the attributes this enricher adds, e.g.
     * ['salesType' => ['type' => 'integer']]. Return [] if it adds none.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mapping(): array;
}
