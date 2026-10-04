<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\NobodysStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Which of the two things is said of a statement no flush owns -- once, and only that one. The
 * one said while a flush ran is a hole no test of a flush reaches when the listener works, which
 * is why it is asked here, of the rule alone.
 */
final class NobodysStatementTest extends TestCase
{
    public function testWhileAFlushRanItIsAHoleWorthReporting(): void
    {
        self::assertSame(
            ['warning: A statement changed status of a App\Order row, keyed by id, tenant, while a flush was running, and no flush claimed it, so it is not in the history. That is a hole in how the audit listener marks what a flush runs, and worth reporting.'],
            self::said(true),
        );
    }

    public function testOutsideEveryFlushItIsTheApplicationsOwnSql(): void
    {
        self::assertSame(
            ['warning: A statement changed status of a App\Order row, keyed by id, tenant, outside every flush, so it is not in the history: SQL the application ran itself, which the bundle does not audit.'],
            self::said(false),
        );
    }

    /** @return list<string> */
    private static function said(bool $whileAFlushRan): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            /**
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                \assert(\is_string($level) && \is_string($message));
                $this->lines[] = $level.': '.strtr($message, ['{field}' => $context['field'], '{class}' => $context['class'], '{key}' => $context['key']]);
            }
        };

        NobodysStatement::say($logger, $whileAFlushRan, 'status', 'App\Order', ['id', 'tenant']);

        return $logger->lines;
    }
}
