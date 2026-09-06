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
     * Those callables are called with the related object — one argument, whatever is
     * on the other side of that association — while a flush is in progress, and are
     * expected to be deterministic and free of side effects: same object in, same
     * value out, and nothing changed on the way. See AuditField for what that rules
     * out and why.
     *
     * The type below says `callable` rather than `callable(object): mixed`, which
     * would describe the call exactly and then refuse the line above it: a closure
     * typed for its own related class is narrower than `object`, and contravariance
     * forbids that, so an application on PHPStan level 8 had to choose between the
     * documented idiom and a green analysis. The prose is the contract here.
     *
     * @return array<string, callable|null>
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
