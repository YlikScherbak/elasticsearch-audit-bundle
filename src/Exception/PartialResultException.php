<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Elasticsearch answered with part of the result: a shard failed, or the search ran
 * out of time and returned what it had.
 *
 * For a search screen that is usually the better trade — some results now beat an
 * error. For an audit trail it is not: "these are the records" would be false, and
 * nothing in the answer says so unless somebody looks. An export is worse still,
 * because it takes its next cursor from the last hit it received: everything the
 * failed shard held before that position would never be read, and the export would
 * finish looking complete.
 *
 * Its message names counts and nothing else, so it is safe to repeat anywhere.
 */
final class PartialResultException extends \RuntimeException implements AuditException, SafeExceptionMessage
{
    public static function shardsFailed(int $failed, int $total): self
    {
        return new self(sprintf('Elasticsearch answered with a partial result: %d of %d shard(s) failed, so the records returned are not all the records there are. Read again once the cluster is healthy — an audit answer that is quietly incomplete is worse than no answer.', $failed, $total));
    }

    public static function stoppedEarly(): self
    {
        return new self('The search stopped early (terminated_early), so what came back is part of what matches, not all of it. An index-level terminate_after is the usual cause — an audit answer that is quietly incomplete is worse than no answer.');
    }

    public static function timedOut(): self
    {
        return new self('The search timed out and Elasticsearch returned what it had, which may be part of the result or none of it. Read again, or raise reader.point_in_time_keep_alive and the request timeout — an audit answer that is quietly incomplete is worse than no answer.');
    }
}
