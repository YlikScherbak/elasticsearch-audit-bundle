<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use PHPUnit\Framework\TestCase;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;

/**
 * What the frame says, and to whom, when something goes wrong inside it.
 *
 * Three different failures meet here and each has an owner. An **overflow** is the
 * valve doing its job, and the operation has to hear about it in a sentence that says
 * how much was dropped — an operator reading "records were dropped" without a number
 * cannot tell a rounding error from an outage. A **comparator that threw** is
 * somebody else's code failing inside the frame: its record went out unfinalized, so
 * nothing is lost, but the mistake still travels the failure policy. And an
 * **operation that failed** owns the exception that surfaces: the frame still closes
 * and still writes what it held, and if that write fails too it must be logged rather
 * than put in front of the reason the operation died.
 *
 * Getting the last one backwards is the expensive kind of wrong: the caller's error
 * handling keys off the cause, and a plain `finally` would hand it the frame's
 * problem with the real one demoted to a previous exception.
 */
final class WhatTheFrameSaysWhenItFailsTest extends TestCase
{
    private InMemoryGateway $gateway;
    private FrameBuffer $buffer;
    private AuditWriter $writer;
    /** @var list<string> */
    private array $logged = [];
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testAnOverflowSaysHowMuchWasDropped(): void
    {
        $frame = $this->frame(new FrameBuffer(maxHeld: 1, throwOnOverflow: true));

        try {
            $frame->coalesce(function (): void {
                $this->writer->record('order', 1, AuditEvent::UPDATE, ['status' => new Change('a', 'b')]);
                $this->writer->record('order', 2, AuditEvent::UPDATE, ['status' => new Change('a', 'b')]);
            });
            self::fail('the operation should have been refused');
        } catch (FrameOverflowException) {
        }

        self::assertCount(1, $this->logged);
        self::assertStringContainsString('coalescing.on_overflow: throw', $this->logged[0]);
        self::assertStringContainsString('the 1 record(s) it had collected were dropped', $this->logged[0], 'the warning did not say how much was lost');
        self::assertStringContainsString('not undone by this', $this->logged[0], 'nor that the database is the caller\'s to roll back');
    }

    public function testAComparatorThatThrewIsReportedAfterTheFrameCloses(): void
    {
        // Nothing was lost — the record went out as it stood — but the application
        // asked for a comparator and the comparator is broken, which is a thing to
        // hear about. It travels the failure policy, like the same mistake anywhere
        // else, and it travels *after* every record was written.
        $frame = $this->frame(new FrameBuffer(new ValueComparator([$this->brokenComparator()])));

        $frame->begin();
        $this->writer->record('order', 1, AuditEvent::UPDATE, ['status' => new Change('a', 'b')]);
        $frame->end();

        self::assertCount(1, $this->gateway->documents['audit_log'] ?? [], 'the record was not written');
        self::assertCount(1, $this->failures(), 'the comparator failure was never reported');
    }

    public function testTheFirstReportingFailureIsTheOneThatSurfaces(): void
    {
        // Under on_failure: throw, reporting a comparator failure raises. Several of
        // them raise several times, and the one the caller gets has to be the first —
        // the others happened after it and describe the same broken comparator.
        $frame = $this->frame(new FrameBuffer(new ValueComparator([$this->brokenComparator()])), FailurePolicy::Throw);

        $frame->begin();
        $this->writer->record('order', 1, AuditEvent::UPDATE, ['status' => new Change('a', 'b')]);
        $this->writer->record('order', 2, AuditEvent::UPDATE, ['status' => new Change('a', 'b')]);

        try {
            $frame->end();
            self::fail('a comparator failure under on_failure: throw should have raised');
        } catch (\Throwable $e) {
            self::assertStringContainsString('order', $e->getMessage());
        }

        self::assertGreaterThanOrEqual(1, \count($this->failures()), 'every failure is reported before one of them raises');
    }

    public function testAnOperationThatFailedKeepsItsOwnException(): void
    {
        // The frame still closes, and closing may fail too — a cluster that went away
        // while the operation was dying. The caller gets the operation's exception; the
        // frame's goes to the log with enough of itself to be found there.
        $this->gateway->failWith = new \RuntimeException('the cluster went away');

        $frame = $this->frame(new FrameBuffer(), FailurePolicy::Throw);

        try {
            $frame->coalesce(function (): void {
                $this->writer->record('order', 1, AuditEvent::UPDATE, ['status' => new Change('a', 'b')]);

                throw new \DomainException('the operation itself failed');
            });
            self::fail('expected the operation to fail');
        } catch (\DomainException $e) {
            self::assertSame('the operation itself failed', $e->getMessage(), 'the frame put its own problem in front of the operation\'s');
        }

        self::assertNotSame([], $this->logged);
        self::assertStringContainsString('could not close cleanly', $this->logged[0]);
        self::assertStringContainsString('order#1 (update)', $this->logged[0], 'and did not say which record it was closing over');
        self::assertStringContainsString('own exception follows', $this->logged[0], 'nor that the real one is elsewhere');
    }

    private function brokenComparator(): ValueComparatorInterface
    {
        return new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('a comparator written elsewhere');
            }
        };
    }

    private function frame(FrameBuffer $buffer, FailurePolicy $policy = FailurePolicy::Log): AuditFrame
    {
        $this->buffer = $buffer;
        $this->writer = $this->writer($buffer, $policy);

        return new AuditFrame($buffer, $this->writer, $this->logger());
    }

    private function writer(FrameBuffer $buffer, FailurePolicy $policy): AuditWriter
    {
        $events = &$this->events;
        $dispatcher = new class($events) implements EventDispatcherInterface {
            /** @param list<object> $events */
            public function __construct(private array &$events)
            {
            }

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };

        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), [], $policy, null, $dispatcher, $buffer);
    }

    /**
     * @return list<RecordFailedEvent>
     */
    private function failures(): array
    {
        return array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof RecordFailedEvent));
    }

    private function logger(): AbstractLogger
    {
        $logged = &$this->logged;

        return new class($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }

            /**
             * @param mixed $level
             * @param mixed $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->logged[] = strtr((string) $message, [
                    '{held}' => (string) ($context['held'] ?? ''),
                    '{reason}' => (string) ($context['reason'] ?? ''),
                ]);
            }
        };
    }
}
