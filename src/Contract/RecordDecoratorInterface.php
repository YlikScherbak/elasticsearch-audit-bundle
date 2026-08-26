<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

use Borsche\ElasticsearchAuditBundle\Model\AuditEntry;

/**
 * Where the application makes a page of history readable.
 *
 * A record stores identifiers — the actor's id, the order's id. A screen wants
 * names. A decorator receives the whole page at once, so it can load everything it
 * needs in one query per entity type instead of one per line, and returns the
 * entries with AuditEntry::withExtra() applied:
 *
 *   $users = $this->users->findByIds(array_unique(array_column($entries, 'actor')));
 *   return array_map(fn ($e) => $e->withExtra(['actorName' => $users[$e->actor]?->getName()]), $entries);
 *
 * Implementations are picked up automatically and run in order.
 */
interface RecordDecoratorInterface
{
    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<AuditEntry> the same entries, same order, possibly with extra data
     */
    public function decorate(array $entries): array;
}
