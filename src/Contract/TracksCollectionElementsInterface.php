<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

/**
 * Implemented beside AuditableInterface by an entity whose to-many fields should
 * also record what changed inside their elements — the attribute equivalent is
 * #[AuditField(trackElements: ...)].
 *
 * It is a separate interface so that AuditableInterface keeps its three methods and
 * nothing that already implements it has to change.
 */
interface TracksCollectionElementsInterface
{
    /**
     * Audited to-many fields whose elements are watched, each mapped to true (every
     * field of the element that changed) or to the list of fields to take:
     *
     *   return ['lines' => ['quantity'], 'tags' => true];
     *
     * @return array<string, bool|list<string>>
     */
    public function getTrackedCollections(): array;
}
