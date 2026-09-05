<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Transport;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Elasticsearch\GatewayInterface;
use Borsche\ElasticsearchAuditBundle\Exception\IndexNotFoundException;
use Borsche\ElasticsearchAuditBundle\Exception\RequestRejectedException;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecord;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordHandler;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecords;
use Borsche\ElasticsearchAuditBundle\Transport\Messenger\IndexAuditRecordsHandler;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * @return iterable<string, array{\Closure(): GatewayInterface}>
     */
    public static function failuresThatAreRetried(): iterable
    {
        // Both leave a handler as themselves, on purpose: a busy cluster and an index
        // caught mid-rollover are asked again rather than sent to the failure transport,
        // and a record must not be lost for arriving during a bad minute. Retries end,
        // though, and what Symfony keeps then is the whole flattened chain.
        yield 'the cluster could not be reached' => [static fn (): GatewayInterface => self::unreachable()];
        yield 'the index was not there' => [static fn (): GatewayInterface => self::missingIndex()];
    }

    /**
     * @param \Closure(): GatewayInterface $gateway
     */
    #[DataProvider('failuresThatAreRetried')]
    public function testARetriedFailureDoesNotCarryTheClustersAnswerIntoTheFailureTransport(\Closure $gateway): void
    {
        try {
            (new IndexAuditRecordHandler($gateway()))(new IndexAuditRecord('audit_log', ['objectType' => 'user'], 'a'));
            self::fail('the handler should have failed');
        } catch (\Throwable $thrown) {
            self::assertStringNotContainsString(self::SECRET, self::everythingSymfonyWouldKeep($thrown));
            self::assertNull($thrown->getPrevious(), 'and nothing behind it for a flattener to walk');
        }
    }

    /**
     * @param \Closure(): GatewayInterface $gateway
     */
    #[DataProvider('failuresThatAreRetried')]
    public function testTheSameHoldsForABatch(\Closure $gateway): void
    {
        try {
            (new IndexAuditRecordsHandler($gateway()))(new IndexAuditRecords([
                ['index' => 'audit_log', 'document' => ['objectType' => 'user'], 'id' => 'a'],
            ]));
            self::fail('the handler should have failed');
        } catch (\Throwable $thrown) {
            self::assertStringNotContainsString(self::SECRET, self::everythingSymfonyWouldKeep($thrown));
            self::assertNull($thrown->getPrevious());
        }
    }

    public function testARetriedFailureKeepsItsClassSoTheStrategyStillReadsIt(): void
    {
        // The chain goes; the class stays. Messenger's retry strategy and any custom one
        // key off the exception, and turning a busy cluster into something else here
        // would trade one leak for a record nobody retries.
        try {
            (new IndexAuditRecordHandler(self::unreachable()))(new IndexAuditRecord('audit_log', ['objectType' => 'user'], 'a'));
            self::fail('the handler should have failed');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf(TransportUnavailableException::class, $thrown);
        }

        try {
            (new IndexAuditRecordHandler(self::missingIndex()))(new IndexAuditRecord('audit_log', ['objectType' => 'user'], 'a'));
            self::fail('the handler should have failed');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf(IndexNotFoundException::class, $thrown);
        }
    }

    /**
     * A gateway that refuses the way a real one does: the reason is already cut, and the
     * client's own exception — status line plus the whole response body — travels as the
     * previous, which is where the value survives.
     */
    private static function refusing(): GatewayInterface
    {
        return self::failingWith(static fn (): \Throwable => RequestRejectedException::because(400, 'failed to parse field [total] of type [long]', self::whatTheClientThrew()));
    }

    /**
     * And one that cannot be reached — the road travelled far more often, and the one
     * with no boundary on it until now: this exception is *retried*, so it left the
     * handler as it was, and Symfony flattened it into the failure transport once the
     * attempts ran out.
     */
    private static function unreachable(): GatewayInterface
    {
        return self::failingWith(static fn (): \Throwable => TransportUnavailableException::because(self::whatTheClientThrew()));
    }

    private static function missingIndex(): GatewayInterface
    {
        return self::failingWith(static fn (): \Throwable => IndexNotFoundException::forIndex('audit_log', self::whatTheClientThrew()));
    }

    /**
     * The client's own exception: the status line followed by the whole response body,
     * which is where a refused document is quoted.
     */
    private static function whatTheClientThrew(): \Throwable
    {
        return new \RuntimeException("400 Bad Request: {\"error\":{\"reason\":\"failed to parse field [total]. Preview of field's value: '".self::SECRET."'\"}}");
    }

    /**
     * @param \Closure(): \Throwable $fail
     */
    private static function failingWith(\Closure $fail): GatewayInterface
    {
        return new class($fail) implements GatewayInterface {
            /** @param \Closure(): \Throwable $fail */
            public function __construct(private readonly \Closure $fail)
            {
            }

            public function index(string $index, array $document, ?string $id = null, bool $refresh = false): void
            {
                throw ($this->fail)();
            }

            public function bulk(array $items): BulkResult
            {
                throw ($this->fail)();
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
