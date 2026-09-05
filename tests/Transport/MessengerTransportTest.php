<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\MessengerTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Borsche\ElasticsearchAuditBundle\Exception\FailureReason;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;

final class MessengerTransportTest extends TestCase
{
    public function testDispatchesAPlainArrayMessage(): void
    {
        $bus = new class implements MessageBusInterface {
            public ?object $dispatched = null;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched = $message;

                return new Envelope($message);
            }
        };

        (new MessengerTransport($bus))->send('audit_log', ['objectType' => 'order'], 'rec-1');

        self::assertInstanceOf(IndexAuditRecord::class, $bus->dispatched);
        self::assertSame('audit_log', $bus->dispatched->index);
        self::assertSame(['objectType' => 'order'], $bus->dispatched->document);
        self::assertSame('rec-1', $bus->dispatched->id);
    }

    public function testABrokenBusIsReportedAsTransportUnavailable(): void
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('AMQP connection refused');
            }
        };

        $this->expectException(TransportUnavailableException::class);
        // The bus's own words are one getPrevious() away, not interpolated into a
        // message that travels into logs and failure transports.
        $this->expectExceptionMessage('RuntimeException');

        (new MessengerTransport($bus))->send('audit_log', []);
    }

    public function testTheHandlerWritesThroughTheGateway(): void
    {
        $gateway = new InMemoryGateway();

        (new IndexAuditRecordHandler($gateway))(new IndexAuditRecord('audit_log', ['objectType' => 'order', 'objectId' => 1], 'rec-1'));

        self::assertSame(['objectType' => 'order', 'objectId' => 1], $gateway->only('audit_log'));
        self::assertSame(['rec-1'], $gateway->ids['audit_log']);
    }

    public function testARedeliveredMessageDoesNotDuplicateTheRecord(): void
    {
        $gateway = new InMemoryGateway();
        $handler = new IndexAuditRecordHandler($gateway);
        $message = new IndexAuditRecord('audit_log', ['objectType' => 'order', 'objectId' => 1], 'rec-1');

        $handler($message);
        $handler($message); // Messenger retried after a timeout on a write that had in fact succeeded

        self::assertCount(1, $gateway->documents['audit_log']);
    }

    public function testADocumentTheMappingRefusesIsNotRetried(): void
    {
        $gateway = new InMemoryGateway();
        $gateway->failOn = ['index' => RequestRejectedException::because(400, 'failed to parse field [objectId]', new \RuntimeException())];

        try {
            (new IndexAuditRecordHandler($gateway))(new IndexAuditRecord('audit_log', ['objectId' => 'x'], 'rec-1'));
            self::fail('expected the message to fail');
        } catch (UnrecoverableExceptionInterface $e) {
            self::assertStringContainsString('failed to parse field [objectId]', $e->getMessage());

            // The gateway's own sentence survives; what it wrapped does not. Symfony
            // stores a failed message's cause as an ErrorDetailsStamp built from
            // FlattenException, which walks the chain — and the client's exception at
            // the end of it is the response body, which is where a refused document's
            // values are. The class of the real cause rides on the reason, for a
            // listener that needs to tell one refusal from another.
            $reason = $e->getPrevious();

            self::assertInstanceOf(FailureReason::class, $reason);
            self::assertSame(RequestRejectedException::class, $reason->causeClass);
            self::assertNull($reason->getPrevious(), 'nothing walkable is left hanging off it');
        }
    }

    public function testAMessageFromBeforeIdsExistedIsStillHandled(): void
    {
        $gateway = new InMemoryGateway();

        (new IndexAuditRecordHandler($gateway))(new IndexAuditRecord('audit_log', ['objectType' => 'order']));

        self::assertSame([null], $gateway->ids['audit_log']);
    }
}
