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
     * Statuses that mean "not now" rather than "not ever": a full write queue, an
     * unavailable shard — and a missing index, which with rollover and the recommended
     * auto_create_index guard is an index mid-rotation, back a moment later. The single
     * write path already retries a 404; a batch must not answer the same moment with
     * the failure transport.
     */
    public const TRANSIENT = [404, 429, 503];

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

            // Read the status first, and let it decide. Looking for an "error" object
            // first meant a position nobody could read — a null item, an action without
            // a status, a 500 that named no error — was counted as written, which is
            // the one answer this class exists to refuse. Success has to be stated.
            if (!\is_array($action) || !is_numeric($action['status'] ?? null)) {
                $failures[$position] = [
                    'status' => 0,
                    'reason' => 'Elasticsearch answered this position with something unreadable, so whether the document was written is unknown.',
                ];

                continue;
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
            if (\in_array($failure['status'], self::TRANSIENT, true)) {
                return true;
            }
        }

        return false;
    }
}
