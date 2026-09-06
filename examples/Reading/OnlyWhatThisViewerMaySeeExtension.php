<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Examples\Reading;

use Borsche\ElasticsearchAuditBundle\Contract\QueryExtensionInterface;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;

/**
 * A visibility boundary, applied to every read.
 *
 * The screen asks for what it wants; this decides what the person in front of it
 * may have. It runs before the query is built, on every read, and it speaks
 * `AuditQuery` rather than Elasticsearch.
 *
 * The `narrow*()` methods are what makes this a boundary rather than a filter:
 * they **intersect** with what was asked, where `with*()` would replace it. An
 * extension cannot widen a request — that is the whole guarantee — and when the
 * intersection is empty the query becomes `matchNothing()`, which the reader
 * answers with an empty page and no request at all.
 *
 * Implementations are picked up automatically and run in order.
 */
final class OnlyWhatThisViewerMaySeeExtension implements QueryExtensionInterface
{
    /**
     * @param list<string> $teamMemberIds who this viewer may see the actions of; a
     *                                    real one asks the security token
     * @param bool         $isAuditor     an auditor sees everything
     */
    public function __construct(
        private readonly array $teamMemberIds,
        private readonly bool $isAuditor = false,
    ) {
    }

    public function extend(AuditQuery $query): AuditQuery
    {
        if ($this->isAuditor) {
            return $query;
        }

        // Nobody to see: say so explicitly. matchNothing() is sticky — no later
        // with*() in the chain can open the answer back up — which is why it is
        // safer than any filter value meaning "impossible".
        if ($this->teamMemberIds === []) {
            return $query->matchNothing();
        }

        // Asked for alice and bob, allowed only bob → bob. Asked for carol, allowed
        // only bob → nothing, rather than "everyone on the team", which is what
        // withActors() here would quietly have meant.
        $query = $query->narrowActors(...$this->teamMemberIds);

        // The same intersection for an attribute the enrichers put on the record.
        return $query->narrowIn('orderCountry', ['PL', 'DE']);
    }
}
