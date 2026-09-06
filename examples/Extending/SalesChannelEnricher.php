<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Extending;

use Borsche\ElasticsearchAuditBundle\Contract\ScopedEnricherInterface;
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
 *
 * This one implements ScopedEnricherInterface rather than AuditEnricherInterface,
 * which adds one method: the object types it is for. It matters as soon as the
 * application routes an object type to an index of its own — audit:index:create has
 * no records to ask supports() about, so without the declaration it would map
 * salesChannel into every index, and audit:check would report it missing from the
 * ones no order is ever written to. It also means supports() has one less thing to
 * repeat.
 */
final class SalesChannelEnricher implements ScopedEnricherInterface
{
    /**
     * Read by the index commands, and by the writer before it asks supports(). `[]`
     * — or plain AuditEnricherInterface — means every object type.
     *
     * @return list<string>
     */
    public function objectTypes(): array
    {
        return ['order'];
    }

    /** @param array<int, string> $channelByOrderId stands for a repository */
    public function __construct(private readonly array $channelByOrderId)
    {
    }

    /**
     * The object type is already answered by objectTypes(), so what is left here is
     * whatever else this enricher needs to be true — nothing, in this case.
     */
    public function supports(AuditRecord $record): bool
    {
        return true;
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
