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
use Symfony\Component\Messenger\MessageBusInterface;

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

        (new MessengerTransport($bus))->send('audit_log', ['objectType' => 'order']);

        self::assertInstanceOf(IndexAuditRecord::class, $bus->dispatched);
        self::assertSame('audit_log', $bus->dispatched->index);
        self::assertSame(['objectType' => 'order'], $bus->dispatched->document);
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
        $this->expectExceptionMessage('AMQP connection refused');

        (new MessengerTransport($bus))->send('audit_log', []);
    }

    public function testTheHandlerWritesThroughTheGateway(): void
    {
        $gateway = new InMemoryGateway();

        (new IndexAuditRecordHandler($gateway))(new IndexAuditRecord('audit_log', ['objectType' => 'order', 'objectId' => 1]));

        self::assertSame(['objectType' => 'order', 'objectId' => 1], $gateway->only('audit_log'));
    }
}
