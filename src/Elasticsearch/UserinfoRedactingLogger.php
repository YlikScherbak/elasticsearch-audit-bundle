<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * The Elasticsearch client logs every request with its full URL, and a host given as
 * http://user:secret@es:9200 — a documented way to configure the client — put the
 * secret in the application log on each call. This sits between the client and the
 * application's logger and blanks the password before anything is written.
 *
 * @internal wraps the logger ClientFactory hands to the client
 */
final class UserinfoRedactingLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    /**
     * @param mixed               $level
     * @param mixed               $message untyped: psr/log 1 declares none
     * @param array<mixed, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->inner->log(
            $level,
            \is_string($message) ? self::redact($message) : $message,
            array_map(static fn (mixed $value): mixed => \is_string($value) ? self::redact($value) : $value, $context),
        );
    }

    private static function redact(string $text): string
    {
        return preg_replace('~//([^/@:\s]+):[^/@\s]+@~', '//$1:***@', $text) ?? $text;
    }
}
