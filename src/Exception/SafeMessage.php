<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Whether a cause's message may be repeated in what the bundle emits.
 *
 * Two things have to hold, and the second one is why this exists rather than a bare
 * `instanceof`: the class declares SafeExceptionMessage, **and** it is one of this
 * bundle's own. A marker interface is public by nature, so on its own it lets any
 * class opt into being trusted — including the enricher whose message the policy was
 * written to keep out of the log. A promise about "sentences we wrote ourselves" can
 * only be made by the code that wrote them.
 *
 * An application that wants foreign messages repeated says so once, in configuration
 * (`redact.failure_details: full`), where it is a decision somebody made rather than a
 * capability a class granted itself.
 *
 * @internal
 */
final class SafeMessage
{
    /**
     * The namespace the bundle's own exceptions live in — all of them, and nothing
     * else. Deliberately not the bundle's root: that also covers its tests, and a rule
     * which lets a test class vouch for itself is not a rule.
     */
    private const OURS = __NAMESPACE__.'\\';

    public static function vouchedFor(\Throwable $e): bool
    {
        return $e instanceof SafeExceptionMessage && str_starts_with($e::class, self::OURS);
    }
}
