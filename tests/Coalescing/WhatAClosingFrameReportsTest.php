<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * What a frame says when it closes and its comparator threw: every failure, the first one raised
 * under "throw", and none of them left in the buffer for the next operation -- whether the frame
 * closed the normal way, failed to write, or was found open and released.
 */
final class WhatAClosingFrameReportsTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private InMemoryGateway $gateway;

    private FrameBuffer $buffer;

    protected function setUp(): void
    {
        $this->logs = [];
        $this->gateway = new InMemoryGateway();
        $this->buffer = new FrameBuffer(new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('the comparator failed');
            }
        });
    }

    public function testAFrameThatFailedToWriteLeavesNoComparatorFailureForTheNextOperation(): void
    {
        $frame = $this->frame(FailurePolicy::Log);
        $frame->begin();
        $this->record(1);
        $this->gateway->failWith = new \RuntimeException('the cluster is down');

        try {
            $frame->end();
        } catch (\Throwable) {
        }

        self::assertSame([], $this->buffer->takeFinalizeFailures(), 'drained with the frame');
    }

    public function testAFrameFoundOpenAndReleasedReportsItsComparatorFailures(): void
    {
        $frame = $this->frame(FailurePolicy::Log);
        $frame->begin();
        $this->record(1);

        $frame->release();

        self::assertSame([], $this->buffer->takeFinalizeFailures(), 'drained');
        self::assertNotSame([], array_filter($this->logs, static fn (array $log): bool => str_contains($log['message'], 'could not be written')), 'and reported');
    }

    public function testAComparatorFailureThatCannotBeReportedWhileAWriteFailsIsSaid(): void
    {
        // Under "throw" reporting the comparator's failure raises, and the write had already
        // failed: the write's exception is the one that leaves, and the other is said.
        $frame = $this->frame(FailurePolicy::Throw);
        $frame->begin();
        $this->record(1);
        $this->gateway->failWith = new \RuntimeException('the cluster is down');

        try {
            $frame->end();
            self::fail('the premise: the write fails');
        } catch (WriteFailedException) {
        }

        $said = array_values(array_filter($this->logs, static fn (array $log): bool => str_starts_with($log['message'], 'A comparator failure could not be reported while the frame was closing')));
        self::assertCount(1, $said);
        self::assertArrayHasKey('reason', $said[0]['context']);
        self::assertArrayHasKey('exception', $said[0]['context']);
    }

    public function testOfSeveralComparatorFailuresTheFirstIsTheOneRaised(): void
    {
        $frame = $this->frame(FailurePolicy::Throw);
        $frame->begin();
        $this->record(1);
        $this->record(2);

        try {
            $frame->end();
            self::fail('the premise: the comparator failures raise');
        } catch (WriteFailedException $raised) {
            self::assertSame(1, $raised->record?->objectId);
        }
    }

    private AuditWriter $writer;

    private function record(int $id): void
    {
        $this->writer->write(new AuditRecord('order', $id, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]));
    }

    private function frame(FailurePolicy $policy): AuditFrame
    {
        $transport = new SyncTransport($this->gateway);
        $this->writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], $policy, $this->logger(), null, $this->buffer);

        return new AuditFrame($this->buffer, $this->writer, $this->logger());
    }

    private function logger(): AbstractLogger
    {
        return new class($this->logs) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
