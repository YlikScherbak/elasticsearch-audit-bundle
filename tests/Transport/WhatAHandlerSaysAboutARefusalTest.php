<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * What a worker is told when the cluster refuses part of a batch.
 *
 * This is the message that ends up in the failure transport, and it is read by somebody
 * at three in the morning who has nothing else: the records are not in the index, the
 * operation that made them finished hours ago, and the only account of what happened is
 * the sentence this handler wrote. So the parts of it are facts and not decoration —
 * which of the batch was refused, what the cluster said about each, and whether anything
 * is going to be tried again.
 *
 * HandlerBoundaryTest asks what must *not* be in that sentence. This one asks what must.
 */
final class WhatAHandlerSaysAboutARefusalTest extends TestCase
{
    public function testABatchWithATransientFailureSaysItIsBeingRetried(): void
    {
        // 429 is the cluster asking for a moment, so the whole batch goes round again —
        // and the exception has to be one Messenger retries, carrying the summary so the
        // retry is not a mystery in the log.
        $handler = new IndexAuditRecordsHandler(self::refusing([
            0 => ['status' => 429, 'reason' => 'too many requests'],
        ], attempted: 2));

        try {
            $handler(new IndexAuditRecords([
                ['index' => 'audit_log', 'document' => ['objectType' => 'order'], 'id' => 'a'],
                ['index' => 'audit_log', 'document' => ['objectType' => 'order'], 'id' => 'b'],
            ]));

            self::fail('the batch should have been refused');
        } catch (TransportUnavailableException $retried) {
            self::assertStringStartsWith('1 of 2 audit records were refused', $retried->getMessage(), 'how much of the batch');
            self::assertStringContainsString('#0 audit_log/a (HTTP 429): too many requests', $retried->getMessage(), 'and which, with what the cluster said');
            // The note is an annotation on the summary and comes after it: read the
            // other way round the sentence opens with a promise and buries what was
            // refused, which is the part somebody is searching the log for.
            self::assertStringEndsWith(' — retrying the batch', $retried->getMessage(), 'and that it is not over');
        }
    }

    public function testAPermanentRefusalCarriesTheStatusOfTheFirstOne(): void
    {
        // Not a guessed status and not the last one: an operator reading the failure
        // transport is looking for what Elasticsearch actually answered, and the batch is
        // reported in the order it was sent.
        $handler = new IndexAuditRecordsHandler(self::refusing([
            0 => ['status' => 400, 'reason' => 'document_parsing_exception'],
            1 => ['status' => 403, 'reason' => 'blocked'],
        ], attempted: 2));

        try {
            $handler(new IndexAuditRecords([
                ['index' => 'audit_log', 'document' => ['objectType' => 'order'], 'id' => 'a'],
                ['index' => 'audit_log', 'document' => ['objectType' => 'order'], 'id' => 'b'],
            ]));

            self::fail('the batch should have been refused');
        } catch (UnrecoverableMessageHandlingException $refused) {
            // Asked of the exception rather than of the sentence: the summary lists every
            // refusal, so both statuses are in the text whichever one was chosen, and a
            // test reading the text would agree with either.
            $rejected = $refused->getPrevious();

            self::assertInstanceOf(RequestRejectedException::class, $rejected);
            self::assertSame(400, $rejected->getCode(), 'the status of the first refusal, not of the last');
            self::assertStringNotContainsString('retrying', $refused->getMessage(), 'and nothing is being tried again');
        }
    }

    public function testARecordFromBeforeIdsTakesItsIdFromTheDocument(): void
    {
        // A message with no id of its own is either older than ids or lost the property
        // to a serializer. The document carries the same id, and using it is what keeps a
        // redelivery from writing a second copy under an id the cluster invents.
        $gateway = new InMemoryGateway();

        (new IndexAuditRecordHandler($gateway))(new IndexAuditRecord('audit_log', ['objectType' => 'order', 'id' => 'from-the-document'], null));

        self::assertSame(['from-the-document'], $gateway->ids['audit_log'] ?? null);
    }

    public function testTheMessageOwnIdIsTheOneUsedWhenBothAreThere(): void
    {
        // The document's copy is a fallback and only that. The two are the same string
        // for every record this bundle builds, and the one place they could differ is a
        // listener that replaced the document after the message was made — at which
        // point the id the queue was told about is the one a redelivery will come back
        // with, and writing under the other one stores the event twice.
        $gateway = new InMemoryGateway();

        (new IndexAuditRecordHandler($gateway))(new IndexAuditRecord('audit_log', ['objectType' => 'order', 'id' => 'from-the-document'], 'from-the-message'));

        self::assertSame(['from-the-message'], $gateway->ids['audit_log'] ?? null);
    }

    public function testAnEmptyIdInTheDocumentIsNotAnId(): void
    {
        // An empty string is not an identifier, and writing under one would be writing
        // under whatever Elasticsearch makes of it — differently on every redelivery.
        $gateway = new InMemoryGateway();

        (new IndexAuditRecordHandler($gateway))(new IndexAuditRecord('audit_log', ['objectType' => 'order', 'id' => ''], null));

        self::assertSame([null], $gateway->ids['audit_log'] ?? null, 'the cluster is left to name it rather than told to use nothing');
    }

    /**
     * A cluster that refuses part of a batch. Written out rather than built on the
     * in-memory gateway, which is final and answers for the whole interface: what this
     * needs is one method telling the truth and the rest never called.
     *
     * @param array<int, array{status: int, reason: string}> $failures
     */
    private static function refusing(array $failures, int $attempted): GatewayInterface
    {
        return new class($failures, $attempted) implements GatewayInterface {
            /** @param array<int, array{status: int, reason: string}> $failures */
            public function __construct(private readonly array $failures, private readonly int $attempted)
            {
            }

            public function bulk(array $items): BulkResult
            {
                return new BulkResult($this->attempted, $this->failures);
            }

            public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
            {
                throw new \LogicException('this test batches');
            }

            public function search(string $index, array $body): array
            {
                return [];
            }

            public function openPointInTime(string $index, string $keepAlive): string
            {
                return 'pit';
            }

            public function searchPointInTime(string $pitId, string $keepAlive, array $body): array
            {
                return [];
            }

            public function closePointInTime(string $pitId): void
            {
            }

            public function indexExists(string $index): bool
            {
                return true;
            }

            public function createIndex(string $index, array $definition): void
            {
            }

            public function mapping(string $index): array
            {
                return [];
            }

            public function indicesAcceptingUnknownFields(string $index): array
            {
                return [];
            }

            public function putMapping(string $index, array $properties): void
            {
            }

            public function settings(string $index): array
            {
                return [];
            }

            public function info(): array
            {
                return ['version' => ['number' => '9.0.0']];
            }
        };
    }
}
