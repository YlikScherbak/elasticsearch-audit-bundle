<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Writer;

use Psr\Clock\ClockInterface;

/**
 * The default clock. Alias borsche_elasticsearch_audit.clock to your own
 * (symfony/clock's MockClock in tests, for example) to control timestamps.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
