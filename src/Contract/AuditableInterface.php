<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Contract;

/**
 * An entity whose lifecycle is recorded automatically.
 *
 * The alternative is the #[Auditable] / #[AuditField] attributes; both describe the
 * same thing and the bundle treats them identically. Use the interface when the
 * field list depends on runtime state or you want closures for representing
 * related objects; use the attributes when a static declaration reads better.
 */
interface AuditableInterface
{
    /**
     * The value stored as "objectType" — what the history is filtered by.
     */
    public function getAuditObjectType(): string;

    /**
     * Fields to record, keyed by property name.
     *
     * For scalar fields the value is null. For associations it is a callable that
     * turns one related object into what should be stored — a name, an id, a small
     * array — since storing the whole entity is neither possible nor useful:
     *
     *   ['title' => null, 'author' => fn (User $u) => $u->getName(), 'tags' => fn (Tag $t) => $t->getLabel()]
     *
     * @return array<string, (callable(object): mixed)|null>
     */
    public function getAuditedFields(): array;

    /**
     * Scalar fields (from the audited ones) recorded on every update even when
     * unchanged — the current status of an order, say, so each history line is
     * readable on its own.
     *
     * @return list<string>
     */
    public function getAlwaysRecordedFields(): array;
}
