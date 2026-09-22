<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

/**
 * When a change happened and who made it, settled where it happened rather than where
 * it is written.
 *
 * Records are usually written moments after they are built, in the same request, and
 * then there is nothing to tell apart: the clock and the security token answer the same
 * either way. The exception is the flush whose publishing was swallowed — a postFlush
 * listener registered before this bundle's throwing — whose records are written by the
 * next flush that comes along. That flush belongs to another request, another user and,
 * if the process ran overnight, another day.
 *
 * So the moment travels with the records. Everything derived from "now" is taken from
 * here when it is given: the timestamp, the actor, and the identifier built from the
 * timestamp.
 *
 * There is no third state for "we could not tell who". An actor that could not be
 * resolved is reported where every other audit failure is reported and carried here as
 * null, which the document stores as no actor at all: `source: null` says the record has
 * no settled actor and does not say why. An installation that needs the difference to be
 * visible chooses on_failure: throw, and then there is no record with an unknown actor
 * to read. A flag saying "unknown" would have to be mapped, and a mapping is forever.
 *
 * The attributes are the same idea one step out: what a MomentEnricherInterface said
 * about this moment, asked once here rather than per record, so that the route a record
 * carries is the route of the request that caused it rather than of the request that
 * happened to write it.
 *
 * **A settled answer, including a settled "nobody".** An actor of null here means the
 * flush had none — a console command, a consumer, a request with no firewall — and not
 * that the question is still open. That is the whole reason this is an object rather
 * than two nullable arguments: `null` for "there was no actor" and `null` for "resolve
 * one" are the same value, and the difference is a record naming whoever happened to be
 * logged in when a background job's history was finally written.
 */
final class Provenance
{
    /**
     * @param array<string, mixed> $attributes what the moment enrichers said about this moment
     */
    public function __construct(
        public readonly \DateTimeImmutable $at,
        public readonly ?string $actor,
        public readonly array $attributes = [],
    ) {
    }
}
