<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * The worker is a boundary of its own, and a harsher one than a log line.
 *
 * When a handler throws, Symfony keeps the failure on the message as an
 * ErrorDetailsStamp built from FlattenException — which walks getPrevious() and keeps
 * every message it finds. That stamp goes to the failure transport and stays there
 * until somebody retries or removes it: a database table, a queue, a file. So an
 * exception chain that is merely awkward in a log is durable evidence here, and the
 * bundle must not hand the worker a cause it would not hand a logger.
 */
final class HandlerBoundaryTest extends TestCase
{
    private const SECRET = 'hunter2-the-actual-password';

    public function testARefusedDocumentDoesNotReachTheFailureTransportWithItsValue(): void
    {
        $gateway = self::refusing();

        try {
            (new IndexAuditRecordHandler($gateway))(new IndexAuditRecord('audit_log', ['objectType' => 'user'], 'a'));
            self::fail('the handler should have refused the document');
        } catch (\Throwable $thrown) {
            self::assertStringNotContainsString(self::SECRET, self::everythingSymfonyWouldKeep($thrown));
        }
    }

    public function testABatchRefusedAsAWholeIsNotCarriedIntoTheFailureTransportEither(): void
    {
        // The whole-request refusal — a 400 or a 403 on the _bulk call itself — never
        // reaches BulkResult, so the per-item path that sanitises reasons is not on this
        // road at all. It was also not caught here, so Messenger would have retried it
        // three times and then stored the chain.
        $gateway = self::refusing();

        try {
            (new IndexAuditRecordsHandler($gateway))(new IndexAuditRecords([
                ['index' => 'audit_log', 'document' => ['objectType' => 'user'], 'id' => 'a'],
            ]));
            self::fail('the handler should have refused the batch');
        } catch (\Throwable $thrown) {
            self::assertStringNotContainsString(self::SECRET, self::everythingSymfonyWouldKeep($thrown));
        }
    }

    /**
     * A gateway that refuses the way a real one does: the reason is already cut, and the
     * client's own exception — status line plus the whole response body — travels as the
     * previous, which is where the value survives.
     */
    private static function refusing(): GatewayInterface
    {
        return new class implements GatewayInterface {
            public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
            {
                throw $this->refusal();
            }

            public function bulk(array $items): BulkResult
            {
                throw $this->refusal();
            }

            private function refusal(): RequestRejectedException
            {
                return RequestRejectedException::because(400, 'failed to parse field [total] of type [long]', new \RuntimeException("400 Bad Request: {\"error\":{\"reason\":\"failed to parse field [total]. Preview of field's value: '".HandlerBoundaryTest::secret()."'\"}}"));
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

            public function putMapping(string $index, array $properties): void
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

            public function settings(string $index): array
            {
                return [];
            }

            public function info(): array
            {
                return [];
            }
        };
    }

    public static function secret(): string
    {
        return self::SECRET;
    }

    /**
     * What ends up on the message, the way Messenger builds it.
     */
    private static function everythingSymfonyWouldKeep(\Throwable $thrown): string
    {
        $said = [];

        for ($flat = FlattenException::createFromThrowable($thrown); $flat !== null; $flat = $flat->getPrevious()) {
            $said[] = $flat->getMessage();
        }

        // And the chain as PHP sees it, in case FlattenException ever stops walking it.
        for ($e = $thrown; $e !== null; $e = $e->getPrevious()) {
            $said[] = $e->getMessage();
        }

        return implode("\n", $said);
    }
}
