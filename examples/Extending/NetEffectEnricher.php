<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Extending;

use Borsche\ElasticsearchAuditBundle\Contract\MergedRecordEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;

/**
 * The other moment an enricher can run at, and why there are two.
 *
 * An ordinary enricher sees a record the moment it is created — before a frame
 * merges it with the other saves of the same operation. That is right for a fact
 * about the *step* (which request, who was authenticated) and wrong for a fact
 * about the *outcome*: a quantity that went 1000 → 1040 → 1000 is no change at
 * all, but an enricher that ran on the last step already decided it moved, and the
 * merged record would carry an attribute contradicting its own changes.
 *
 * This one runs once, on the record as it will be stored — on whatever the frame
 * merged, and on the record itself when no frame was open, so what it says does
 * not depend on whether the caller happened to open one.
 *
 * Everything else is the same interface: supports(), enrich(), mapping().
 */
final class NetEffectEnricher implements MergedRecordEnricherInterface
{
    public function supports(AuditRecord $record): bool
    {
        return $record->objectType === 'order' && isset($record->changes['totalCents']);
    }

    public function enrich(AuditRecord $record): AuditRecord
    {
        $change = $record->changes['totalCents'] ?? null;

        // Inside the writer a change is still a Change; read back from the index it
        // is the array it was stored as. Handling both is what makes an enricher
        // safe to reuse.
        if ($change instanceof Change) {
            [$old, $new] = [$change->old, $change->new];
        } elseif (Change::isPair($change)) {
            [$old, $new] = [$change['old'], $change['new']];
        } else {
            return $record;
        }

        if (!\is_int($old) || !\is_int($new)) {
            return $record;
        }

        // The net effect of the whole operation, which is the number a report wants
        // and no single step could have given.
        return $record->withAttributes(['totalDeltaCents' => $new - $old]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function mapping(): array
    {
        return ['totalDeltaCents' => ['type' => 'integer']];
    }
}
