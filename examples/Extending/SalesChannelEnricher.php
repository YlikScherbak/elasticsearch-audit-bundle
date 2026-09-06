<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Extending;

use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * Where the application adds what only it knows.
 *
 * A record carries the generic facts. Anything you want to **filter the history by
 * later** — the sales channel of an order, the warehouse of a movement, the tenant
 * — is a denormalised attribute added at write time. Denormalised on purpose: the
 * history answers questions years after the order changed channel, and a join to
 * the current row would answer a different question.
 *
 * `mapping()` is the half people forget. An attribute nobody mapped is stored and
 * not indexed, so the filter that reads it silently matches nothing;
 * `audit:index:create` and `audit:check` both read this method.
 *
 * Implementations are picked up automatically.
 */
final class SalesChannelEnricher implements AuditEnricherInterface
{
    /** @param array<int, string> $channelByOrderId stands for a repository */
    public function __construct(private readonly array $channelByOrderId)
    {
    }

    public function supports(AuditRecord $record): bool
    {
        return $record->objectType === 'order';
    }

    /**
     * Called once per record, at the moment it is created — so this is the place
     * for a fact about the step, not about the outcome of a whole operation. When
     * the difference matters, implement MergedRecordEnricherInterface instead.
     *
     * It must not throw for a record it does not support, and it should not reach
     * across the network: this runs inside the request that is being audited.
     */
    public function enrich(AuditRecord $record): AuditRecord
    {
        $id = $record->objectId;

        if (!\is_int($id) || !isset($this->channelByOrderId[$id])) {
            return $record;
        }

        return $record->withAttributes(['salesChannel' => $this->channelByOrderId[$id]]);
    }

    /**
     * The types the index needs for what enrich() adds. `keyword` rather than
     * `text`, because this is filtered and aggregated by, never searched in.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mapping(): array
    {
        return ['salesChannel' => ['type' => 'keyword']];
    }
}
