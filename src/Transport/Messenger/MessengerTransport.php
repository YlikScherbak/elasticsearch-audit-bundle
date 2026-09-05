<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Transport\Messenger;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Exception\AuditException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Borsche\ElasticsearchAuditBundle\Transport\BatchTransportInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Hands documents to Messenger; the handlers write them from the worker. The
 * request only pays for the dispatch, and Elasticsearch being slow or down no
 * longer touches response times. A batch travels as one message and becomes one
 * _bulk call on the other side.
 */
final class MessengerTransport implements BatchTransportInterface
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function send(string $index, array $document, ?string $id = null): void
    {
        $this->dispatch(new IndexAuditRecord($index, $document, $id));
    }

    public function sendMany(array $items): BulkResult
    {
        if ($items !== []) {
            $this->dispatch(new IndexAuditRecords($items));
        }

        // Nothing has been written yet, so nothing has failed yet: the worker finds out.
        return BulkResult::allSucceeded(\count($items));
    }

    private function dispatch(object $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (HandlerFailedException $e) {
            // A message routed to sync:// is handled inside this dispatch, so what comes
            // back is the handler's own failure wrapped by Messenger. Calling that "the
            // cluster is unreachable" turns a document Elasticsearch refused for good
            // into something the write path retries forever, and buries what actually
            // happened one level further down. The handlers have already classified it
            // and cut what must not travel; this passes their answer through.
            throw self::whatTheHandlerSaid($e);
        } catch (AuditException $e) {
            // Raised by the bundle itself on the way to the bus — a frame refusing an
            // operation, a record that cannot be built. It says what is wrong already.
            throw $e;
        } catch (\Throwable $e) {
            throw TransportUnavailableException::because($e);
        }
    }

    /**
     * The handler's own exception, out of Messenger's wrapper.
     *
     * HandlerFailedException carries the real causes; the first one this bundle
     * recognises is the answer. Anything else is somebody else's middleware or handler
     * on the same bus, and that is a transport failure as far as the writer is
     * concerned — the record did not get where it was going.
     */
    private static function whatTheHandlerSaid(HandlerFailedException $failed): \Throwable
    {
        foreach ($failed->getWrappedExceptions() as $wrapped) {
            // Down the chain as well as at the top: this bundle's own handlers raise
            // UnrecoverableMessageHandlingException — Messenger's way of saying "do not
            // retry this one" — with the audit exception behind it, so the answer sits a
            // step below what the wrapper holds.
            for ($e = $wrapped, $step = 0; $e !== null && $step < 8; $e = $e->getPrevious(), ++$step) {
                if ($e instanceof AuditException) {
                    return $e;
                }
            }
        }

        return TransportUnavailableException::because($failed);
    }
}
