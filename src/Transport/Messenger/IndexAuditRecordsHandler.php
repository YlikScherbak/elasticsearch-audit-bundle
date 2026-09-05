<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\FailureReason;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\SafeMessage;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The worker side of a batch: one _bulk call.
 *
 * A cluster that is unreachable propagates as TransportUnavailableException and is
 * retried — harmlessly, since every document travels with its id and overwrites
 * itself. Elasticsearch answers 200 for the request and judges each document
 * separately, so what the handler does with a partly written batch is decided per
 * item, from the statuses the response gave:
 *
 * - one of them was refused for now (a full write queue, an unavailable shard):
 *   the whole message is retried, because a record must not be lost for arriving
 *   during a busy hour. What was written is written again, which changes nothing;
 * - all of them were refused for good (the mapping disagrees with the document):
 *   retrying cannot help, so the message goes to the failure transport at once.
 *
 * A batch holding both is retried. The permanent one fails again on every attempt
 * and reaches the failure transport at the end of them, by which time the transient
 * one is likely written — the opposite order would trade a recoverable record for a
 * faster answer about an unrecoverable one.
 *
 * A third outcome is neither: IndexNotFoundException. It leaves the handler as itself,
 * so Messenger's default strategy retries it — which is what an index caught
 * mid-rollover needs, and why it is not made unrecoverable. Worth knowing if you key a
 * custom retry strategy on the exception class rather than on the two above.
 *
 * @internal invoked by Messenger
 */
final class IndexAuditRecordsHandler
{
    public function __construct(private readonly GatewayInterface $gateway)
    {
    }

    public function __invoke(IndexAuditRecords $message): void
    {
        try {
            $result = $this->gateway->bulk($message->items);
        } catch (TransportUnavailableException|IndexNotFoundException $e) {
            // Retried, so it keeps its class; but not the cause it was built from. See
            // the single-record handler: what the client puts in a message is the status
            // line followed by the whole response body, and the failure transport keeps
            // a flattened chain for as long as the message is in it.
            throw SafeMessage::withoutTheChain($e);
        } catch (RequestRejectedException $e) {
            // A refusal of the whole request — a 400 or a 403 on the _bulk call itself —
            // never reaches BulkResult, so the per-item road that sanitises reasons is
            // not this one. Uncaught, it was also retried three times before Messenger
            // stored the chain in the failure transport. See the single-record handler
            // for why the chain must not travel.
            $safe = FailureReason::keepingTheMessageOf($e);

            throw new UnrecoverableMessageHandlingException($safe->getMessage(), 0, $safe);
        }

        if (!$result->hasFailures()) {
            return;
        }

        $reasons = [];
        $statuses = [];

        foreach ($result->failures as $position => $failure) {
            $item = $message->items[$position];
            $reasons[] = sprintf('#%d %s%s (HTTP %d): %s', $position, $item['index'], $item['id'] !== null ? '/'.$item['id'] : '', $failure['status'], $failure['reason']);
            $statuses[] = $failure['status'];
        }

        $summary = sprintf(
            '%d of %d audit records were refused: %s',
            \count($result->failures),
            $result->attempted,
            implode('; ', $reasons),
        );

        if ($result->hasTransientFailures()) {
            throw TransportUnavailableException::saying($summary.' — retrying the batch');
        }

        // The status of the first refusal, not a guessed one: an operator reading the
        // failure transport should see what Elasticsearch actually answered.
        $rejected = RequestRejectedException::because($statuses[0], $summary, new \RuntimeException('bulk items rejected'));

        throw new UnrecoverableMessageHandlingException($rejected->getMessage(), 0, $rejected);
    }
}
