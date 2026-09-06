<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

/**
 * Whether a cluster knows the parameter that keeps a refused document out of the
 * error it answers with.
 *
 * One place because two ask, and they were not asking the same way: audit:check
 * parsed the version and the gateway did not look at all — it sent the parameter to
 * every cluster and let a 400 be the answer.
 *
 * @internal
 */
final class ClusterVersion
{
    /**
     * @param string $version what `info()` reports, e.g. "8.19.0"
     */
    public static function knowsIncludeSourceOnError(string $version): bool
    {
        if (preg_match('~^(\d+)\.(\d+)~', $version, $found) !== 1) {
            // A version nobody can parse is treated as new enough. The alternative is to
            // withhold the parameter from a cluster that most likely understands it,
            // which turns an unreadable version string into a silently weaker guarantee;
            // sending it is wrong loudly instead, and audit:check reads the same string.
            return true;
        }

        [$major, $minor] = GatewayInterface::MINIMUM_VERSION;

        return (int) $found[1] > $major || ((int) $found[1] === $major && (int) $found[2] >= $minor);
    }
}
