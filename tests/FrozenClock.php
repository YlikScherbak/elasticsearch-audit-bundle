<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $now = new \DateTimeImmutable('2026-08-26 12:00:00', new \DateTimeZone('UTC')))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
