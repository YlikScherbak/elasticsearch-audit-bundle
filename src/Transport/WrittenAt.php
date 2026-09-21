<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Psr\Clock\ClockInterface;

/**
 * The other timestamp: when the bundle started the write that produced the version
 * of the document now in the index.
 *
 * `loggedAt` is when the change happened, and since the flush that sees a change may
 * not be the flush that writes it, the two are no longer the same number. That
 * difference is the only thing that can tell an operator their history is being
 * published late — a swallowed postFlush, an outbox nobody consumes, a worker that
 * has been down since Friday — and without a second timestamp it is invisible:
 * every record reads as though it arrived the moment it happened.
 *
 * Stamped by the bundle's own write path rather than by the gateway, because the
 * gateway is the seam the tests replace and a field no test can see is a field no
 * test can guard. Set on every attempt, so a redelivered message reports the attempt
 * that actually wrote the document rather than the one that timed out; the document
 * is written under the record's id either way, so the last attempt is the one in the
 * index.
 *
 * It is an ordinary declared field, not a base field of AuditRecord: `writtenAt` is
 * filterable and sortable through the same generic attribute path as an enricher's,
 * which is what an operator asking "what has been written in the last hour, whenever
 * it happened" needs, and reserving the name would have taken that away to prevent
 * a clash the mapping already refuses.
 */
final class WrittenAt
{
    public const FIELD = AuditRecord::WRITTEN_AT;

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function on(array $document, ?ClockInterface $clock): array
    {
        return array_replace($document, [self::FIELD => self::now($clock)]);
    }

    /**
     * One timestamp for the whole batch: the request is one attempt, and a per-item
     * clock reading would only describe how long the loop took.
     *
     * @param list<array{index: string, document: array<string, mixed>, id: string}> $items
     *
     * @return list<array{index: string, document: array<string, mixed>, id: string}>
     */
    public static function onEach(array $items, ?ClockInterface $clock): array
    {
        $at = self::now($clock);
        $stamped = [];

        // Rebuilt rather than written into in place: the older PHPStan the lowest
        // supported dependencies pin loses the list-ness of an array assigned through
        // its keys, and a list is what bulk() is promised.
        foreach ($items as $item) {
            $item['document'] = array_replace($item['document'], [self::FIELD => $at]);
            $stamped[] = $item;
        }

        return $stamped;
    }

    /**
     * A clock is optional so that constructing a transport by hand — which the
     * container never does — stays possible without one. The fallback is the same
     * system clock in everything but name; what it cannot be is skipping the field,
     * which would make the guarantee depend on how the object was built.
     */
    private static function now(?ClockInterface $clock): string
    {
        $at = $clock !== null ? $clock->now() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format(AuditRecord::DATE_FORMAT);
    }
}
