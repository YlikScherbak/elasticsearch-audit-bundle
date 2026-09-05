<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;

/**
 * What came back from one _bulk request. Elasticsearch answers 200 for the request
 * as a whole and reports each document separately, so a batch can be partly
 * written: the caller gets the positions that failed and why, and decides per
 * record — which is what the failure policy does.
 */
final class BulkResult
{
    /**
     * Statuses below 500 that still mean "not now" rather than "not ever": a full write
     * queue (429), and a missing index (404), which with rollover and the recommended
     * auto_create_index guard is an index mid-rotation, back a moment later. Everything
     * from 500 up is transient too and needs no list — see hasTransientFailures().
     */
    public const TRANSIENT = [404, 429];

    /**
     * @param int                                                    $attempted how many items were sent
     * @param array<int, array{status: int, reason: string}>          $failures  keyed by the item's position in the batch
     */
    public function __construct(
        public readonly int $attempted,
        public readonly array $failures = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(0);
    }

    /**
     * Everything went through — or nothing was sent.
     */
    public static function allSucceeded(int $attempted): self
    {
        return new self($attempted);
    }

    /**
     * Reads Elasticsearch's _bulk response: one entry per item, in order, each under
     * its action name ("index" here), with an "error" object when it failed.
     *
     * @param array<string, mixed> $response
     */
    public static function fromResponse(array $response, int $attempted): self
    {
        $items = $response['items'] ?? null;

        // One entry per item, in the order they were sent. Anything else — a truncated
        // body, an answer belonging to another request — leaves no way to tell which
        // documents were written, and counting the missing ones as written is the one
        // answer an audit trail must not give.
        if (!\is_array($items) || \count($items) !== $attempted) {
            throw TransportUnavailableException::because(new \UnexpectedValueException(sprintf(
                'Elasticsearch answered a bulk request of %d document(s) with %d item(s), expected %d.',
                $attempted,
                \is_array($items) ? \count($items) : 0,
                $attempted,
            )));
        }

        $failures = [];

        foreach (array_values($items) as $position => $item) {
            $action = \is_array($item) ? reset($item) : null;

            // A position nobody can read is not a failed document — it is an answer
            // that cannot be trusted at all, and the difference decides what happens
            // next: a failure is classified (and an unclassifiable one was being
            // classified as permanent, so the batch went to the failure transport),
            // while an unreadable answer means the whole response is untrustworthy and
            // the batch has to be sent again. Which is safe: every document carries
            // its own id and overwrites itself.
            if (!\is_array($action) || !is_numeric($action['status'] ?? null)) {
                throw TransportUnavailableException::because(new \UnexpectedValueException(sprintf(
                    'Elasticsearch answered position %d of a bulk request with something that could not be read as a result, so whether those documents were written is unknown.',
                    $position,
                )));
            }

            $status = (int) $action['status'];

            if ($status >= 200 && $status < 300) {
                continue;
            }

            $error = \is_array($action['error'] ?? null) ? $action['error'] : ['reason' => \is_scalar($action['error'] ?? null) ? (string) $action['error'] : null];
            $reason = \is_string($error['reason'] ?? null) && $error['reason'] !== ''
                ? $error['reason']
                : (\is_string($error['type'] ?? null) && $error['type'] !== '' ? $error['type'] : 'rejected with status '.$status);

            $failures[$position] = [
                'status' => $status,
                'reason' => RequestRejectedException::withoutValuePreview($reason),
            ];
        }

        return new self($attempted, $failures);
    }

    public function succeeded(): int
    {
        return $this->attempted - \count($this->failures);
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    public function failed(int $position): bool
    {
        return isset($this->failures[$position]);
    }

    /**
     * Whether any of the refusals was the cluster asking for that document again rather
     * than refusing it: a full write queue (429) or a shard that was not available (503).
     *
     * A batch holding one of these has to be sent again as a whole. Re-sending what was
     * already written costs nothing — every document travels with its id and overwrites
     * itself — while dropping a record because the cluster was busy costs the trail the
     * hour it most needed to describe.
     */
    public function hasTransientFailures(): bool
    {
        foreach ($this->failures as $failure) {
            // Every server error, not a list of the ones seen so far: the single-write
            // path already treats any 5xx as "not now", and the same refusal must not
            // mean two different things depending on how many records a flush produced.
            if (\in_array($failure['status'], self::TRANSIENT, true) || $failure['status'] >= 500) {
                return true;
            }
        }

        return false;
    }
}
