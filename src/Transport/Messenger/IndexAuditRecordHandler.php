<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\FailureReason;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The worker side of MessengerTransport.
 *
 * A cluster that is unreachable propagates as TransportUnavailableException and is
 * retried by Messenger's strategy — the right place for a flaky cluster, and safe,
 * because the document is written under the record's id. A document Elasticsearch
 * refuses will be refused again, so that is raised as unrecoverable: Messenger sends
 * the message to the failure transport at once instead of around the retry loop.
 *
 * A third outcome is neither: IndexNotFoundException. It leaves the handler as itself,
 * so Messenger's default strategy retries it — which is what an index caught
 * mid-rollover needs, and why it is not made unrecoverable. Worth knowing if you key a
 * custom retry strategy on the exception class rather than on the two named above.
 *
 * @internal invoked by Messenger
 */
final class IndexAuditRecordHandler
{
    public function __construct(private readonly GatewayInterface $gateway)
    {
    }

    public function __invoke(IndexAuditRecord $message): void
    {
        try {
            $this->gateway->index($message->index, $message->document, $message->id);
        } catch (RequestRejectedException $e) {
            // The chain stops here. Symfony keeps a failed message's cause as an
            // ErrorDetailsStamp built from FlattenException, which walks getPrevious()
            // and keeps every message it finds — and that stamp lives in the failure
            // transport until somebody retries or removes it. The gateway's own sentence
            // is cut and safe; the client's exception behind it is the status line
            // followed by the whole response body, which is where a refused document's
            // values are. FailureReason keeps the first and drops the second.
            $safe = FailureReason::keepingTheMessageOf($e);

            throw new UnrecoverableMessageHandlingException($safe->getMessage(), 0, $safe);
        }
    }
}
