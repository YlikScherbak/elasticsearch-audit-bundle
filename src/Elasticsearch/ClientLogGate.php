<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

/**
 * What the Elasticsearch client is allowed to say into the application's log.
 *
 * The client is talkative in ways an audit trail cannot afford, and none of it is
 * configurable at the client:
 *
 * - at **debug** it logs `Headers: … Body: …` for the request *and* the response. The
 *   request body is the audited document — the whole `changes` payload — so an
 *   environment running at debug wrote every redacted value into the log once per
 *   write, and a rejected document came back quoted in the response. Nothing at that
 *   level is passed on.
 * - at **info** the message is what an operator actually wants (method, URL, status),
 *   but the context carries the whole PSR-7 request and response objects, and a
 *   processor or formatter that serialises context reaches the body through them.
 *   The message goes; the objects do not.
 * - a host given as `http://user:secret@es:9200` — a documented way to configure the
 *   client — puts the password in the URL it logs. It is blanked wherever it appears.
 *
 * What is left is method, URL, status and retry count: enough to see the traffic,
 * with none of the payload.
 *
 * @internal wraps the logger ClientFactory hands to the client
 */
final class ClientLogGate implements LoggerInterface
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
        if (\is_string($level) && strtolower($level) === LogLevel::DEBUG) {
            return;
        }

        $this->inner->log(
            $level,
            \is_string($message) ? self::redact($message) : $message,
            self::safeContext($context),
        );
    }

    /**
     * @param array<mixed, mixed> $context
     *
     * @return array<mixed, mixed>
     */
    private static function safeContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            // Deny by default. Dropping PSR-7 messages by type closed the leak the
            // client has today — the request object carries the audited document as its
            // body — and it depended on the shape of somebody else's context: a client
            // that one day passes ['request' => ['body' => …]] or a DTO of its own would
            // have walked straight through. What is kept instead is what cannot carry a
            // body: scalars, with strings redacted for credentials. Everything else goes,
            // including arrays and objects, and what is lost with it is a retry count's
            // worth of detail — method, URL and status are in the message itself.
            if (!\is_scalar($value)) {
                continue;
            }

            $safe[$key] = \is_string($value) ? self::redact($value) : $value;
        }

        return $safe;
    }

    private static function redact(string $text): string
    {
        return preg_replace('~//([^/@:\s]+):[^/@\s]+@~', '//$1:***@', $text) ?? $text;
    }
}
