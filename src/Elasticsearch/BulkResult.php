<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;

/**
 * What came back from one _bulk request. Elasticsearch answers 200 for the request
 * as a whole and reports each document separately, so a batch can be partly
 * written: the caller gets the positions that failed and why, and decides per
 * record — which is what the failure policy does.
 */
final class BulkResult
{
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
        $failures = [];
        $items = $response['items'] ?? [];

        if (\is_array($items)) {
            foreach (array_values($items) as $position => $item) {
                $action = \is_array($item) ? reset($item) : null;

                if (!\is_array($action) || !isset($action['error'])) {
                    continue;
                }

                $error = \is_array($action['error']) ? $action['error'] : ['reason' => (string) $action['error']];
                $reason = \is_string($error['reason'] ?? null) && $error['reason'] !== '' ? $error['reason'] : ($error['type'] ?? 'rejected');

                $failures[$position] = [
                    'status' => (int) ($action['status'] ?? 400),
                    'reason' => RequestRejectedException::withoutValuePreview((string) $reason),
                ];
            }
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
}
