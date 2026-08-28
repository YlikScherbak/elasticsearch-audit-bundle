<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\MessengerTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;

final class MessengerBatchTest extends TestCase
{
    public function testABatchIsOneMessage(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };

        $items = [
            ['index' => 'audit_log', 'document' => ['objectId' => 1], 'id' => 'a'],
            ['index' => 'audit_auth', 'document' => ['objectId' => 2], 'id' => 'b'],
        ];

        $result = (new MessengerTransport($bus))->sendMany($items);

        self::assertCount(1, $bus->dispatched);
        self::assertInstanceOf(IndexAuditRecords::class, $bus->dispatched[0]);
        self::assertSame($items, $bus->dispatched[0]->items);
        self::assertFalse($result->hasFailures(), 'nothing has been written yet, so nothing has failed yet');
        self::assertSame(2, $result->attempted);
    }

    public function testAnEmptyBatchDispatchesNothing(): void
    {
        $bus = new class implements MessageBusInterface {
            public int $calls = 0;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ++$this->calls;

                return new Envelope($message);
            }
        };

        (new MessengerTransport($bus))->sendMany([]);

        self::assertSame(0, $bus->calls);
    }

    public function testTheHandlerMakesOneBulkCall(): void
    {
        $gateway = new InMemoryGateway();

        (new IndexAuditRecordsHandler($gateway))(new IndexAuditRecords([
            ['index' => 'audit_log', 'document' => ['objectId' => 1], 'id' => 'a'],
            ['index' => 'audit_log', 'document' => ['objectId' => 2], 'id' => 'b'],
        ]));

        self::assertCount(1, $gateway->bulks);
        self::assertCount(2, $gateway->documents['audit_log']);
    }

    public function testRefusedItemsFailTheMessageWithTheirPositionsAndReasons(): void
    {
        $gateway = new InMemoryGateway();
        $gateway->rejectInBulk = static fn (array $document) => $document['objectId'] === 2;

        try {
            (new IndexAuditRecordsHandler($gateway))(new IndexAuditRecords([
                ['index' => 'audit_log', 'document' => ['objectId' => 1], 'id' => 'a'],
                ['index' => 'audit_log', 'document' => ['objectId' => 2], 'id' => 'b'],
            ]));
            self::fail('expected the message to fail');
        } catch (UnrecoverableExceptionInterface $e) {
            // Messenger retries everything except this: a document the mapping refuses will be
            // refused again, so the message goes to the failure transport straight away.
            self::assertStringContainsString('1 of 2 audit records were refused', $e->getMessage());
            self::assertStringContainsString('#1 audit_log/b: rejected by the test', $e->getMessage());
            self::assertInstanceOf(RequestRejectedException::class, $e->getPrevious(), 'the bundle\'s own exception travels along for whoever inspects the failed message');
        }

        self::assertCount(1, $gateway->documents['audit_log'], 'the accepted item was written');
    }

    public function testAnUnreachableClusterIsLeftToTheRetryStrategy(): void
    {
        $gateway = new InMemoryGateway();
        $gateway->failWith = new \RuntimeException('connection refused');

        try {
            (new IndexAuditRecordsHandler($gateway))(new IndexAuditRecords([['index' => 'audit_log', 'document' => ['objectId' => 1], 'id' => 'a']]));
            self::fail('expected TransportUnavailableException');
        } catch (TransportUnavailableException $e) {
            self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, $e, 'a cluster that is down may be up on the next try');
        }
    }
}
