<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The worker side of a batch: one _bulk call.
 *
 * A cluster that is unreachable propagates as TransportUnavailableException and is
 * retried — harmlessly, since every document travels with its id. A document
 * Elasticsearch refuses cannot be fixed by retrying (the mapping disagrees with
 * it), so the handler raises an unrecoverable exception naming the positions and
 * reasons, with the bundle's RequestRejectedException underneath: Messenger sends
 * the message to the failure transport at once, and the accepted items of the same
 * batch are already written.
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
        $result = $this->gateway->bulk($message->items);

        if (!$result->hasFailures()) {
            return;
        }

        $reasons = [];

        foreach ($result->failures as $position => $failure) {
            $item = $message->items[$position];
            $reasons[] = sprintf('#%d %s%s: %s', $position, $item['index'], $item['id'] !== null ? '/'.$item['id'] : '', $failure['reason']);
        }

        $rejected = RequestRejectedException::because(
            400,
            sprintf('%d of %d audit records were refused: %s', \count($result->failures), $result->attempted, implode('; ', $reasons)),
            new \RuntimeException('bulk items rejected'),
        );

        throw new UnrecoverableMessageHandlingException($rejected->getMessage(), 0, $rejected);
    }
}
