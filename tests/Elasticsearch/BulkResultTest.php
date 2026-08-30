<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Elasticsearch;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\BulkResult;
use Borsche\ElasticsearchAuditBundle\Exception\TransportUnavailableException;
use PHPUnit\Framework\TestCase;

final class BulkResultTest extends TestCase
{
    public function testACleanResponseHasNoFailures(): void
    {
        $result = BulkResult::fromResponse(['errors' => false, 'items' => [
            ['index' => ['_index' => 'audit_log', '_id' => 'a', 'status' => 201]],
            ['index' => ['_index' => 'audit_log', '_id' => 'b', 'status' => 201]],
        ]], 2);

        self::assertFalse($result->hasFailures());
        self::assertSame(2, $result->succeeded());
        self::assertSame(2, $result->attempted);
    }

    public function testFailuresAreKeyedByPositionWithElasticsearchsReason(): void
    {
        $result = BulkResult::fromResponse(['errors' => true, 'items' => [
            ['index' => ['_index' => 'audit_log', 'status' => 201]],
            ['index' => ['_index' => 'audit_log', 'status' => 400, 'error' => ['type' => 'document_parsing_exception', 'reason' => "failed to parse field [objectId] of type [integer]"]]],
            ['index' => ['_index' => 'audit_log', 'status' => 201]],
            ['index' => ['_index' => 'audit_log', 'status' => 429, 'error' => ['type' => 'es_rejected_execution_exception']]],
        ]], 4);

        self::assertTrue($result->hasFailures());
        self::assertSame(2, $result->succeeded());
        self::assertSame([1, 3], array_keys($result->failures));
        self::assertSame(400, $result->failures[1]['status']);
        self::assertStringContainsString('failed to parse field [objectId]', $result->failures[1]['reason']);
        self::assertSame('es_rejected_execution_exception', $result->failures[3]['reason'], 'the type stands in when there is no reason');
        self::assertTrue($result->failed(3));
        self::assertFalse($result->failed(2));
    }

    public function testTheValuePreviewElasticsearchAppendsIsNotKept(): void
    {
        $result = BulkResult::fromResponse(['errors' => true, 'items' => [
            ['index' => ['status' => 400, 'error' => ['type' => 'document_parsing_exception', 'reason' => "[1:13] failed to parse field [email] of type [integer] in document with id 'abc'. Preview of field's value: 'alice@example.com'"]]],
        ]], 1);

        self::assertSame("[1:13] failed to parse field [email] of type [integer] in document with id 'abc'", $result->failures[0]['reason'], 'the refused value is exactly what must not end up in a log or an event');
    }

    public function testAResponseThatCannotBeReadIsNotFiveSuccesses(): void
    {
        // It used to answer "all five written", which is the one thing nobody knows here.
        $this->expectException(TransportUnavailableException::class);
        $this->expectExceptionMessage('with 0 item(s), expected 5');

        BulkResult::fromResponse(['took' => 3], 5);
    }

    public function testEmptyAndAllSucceeded(): void
    {
        self::assertSame(0, BulkResult::empty()->attempted);
        self::assertSame(7, BulkResult::allSucceeded(7)->succeeded());
    }
}
