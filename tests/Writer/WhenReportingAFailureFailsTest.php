<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Writer;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator;
use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent;
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
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;

/**
 * The last line written when saying what went wrong goes wrong too.
 *
 * Reporting a failure is not a quiet operation here: it dispatches an event the
 * application listens to, and under `on_failure: throw` it raises. Both of those are
 * somebody else's code, and both can fail while the writer is already in a catch block
 * holding the failure that actually matters.
 *
 * What the writer must not do then is replace the first failure with the second — that
 * is asserted here and elsewhere. What it must also not do is go silent: these two log
 * lines are the only place a swallowed comparator failure or a broken listener is ever
 * mentioned, and a line whose `{reason}` never got a value is a sentence ending in a
 * placeholder, which is how a log entry stops being worth reading.
 */
final class WhenReportingAFailureFailsTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    private InMemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
    }

    public function testAComparatorFailureSwallowedByAFailedWriteIsStillWrittenDown(): void
    {
        // Two failures at once: the frame overflowed and handed its records back, the
        // comparator threw while finalizing them, and the write of those records failed
        // as well. The write's exception is what the caller gets — a comparator's
        // complaint in its place would hide the thing to act on — so the comparator's
        // is swallowed on purpose, and this line is the whole of what is left of it.
        $this->gateway->failWith = new \RuntimeException('the cluster went away');

        $buffer = new FrameBuffer(new ValueComparator([self::brokenComparator()]), maxHeld: 1);
        $writer = $this->writer($buffer, FailurePolicy::Throw);

        $buffer->open();

        try {
            // Two records of the same type: the second is what pushes the buffer past
            // max_held, so the first is finalized and handed back mid-operation.
            $writer->write(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]));
            $writer->write(new AuditRecord('order', 2, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]));
        } catch (\Throwable $thrown) {
            self::assertStringNotContainsString('comparator', $thrown->getMessage(), 'the comparator complaint was put in front of the failed write');
        }

        self::assertNotSame([], $this->logged, 'nothing was written down about the comparator failure at all');
        self::assertStringContainsString('A comparator failure could not be reported', implode("\n", $this->logged));
        self::assertStringNotContainsString('{reason}', implode("\n", $this->logged), 'the line was logged with its placeholder still in it');
    }

    public function testAListenerThatThrewWhileAFailureWasReportedIsNamedInTheLog(): void
    {
        // RecordFailedEvent is how an application hears that a record did not make it,
        // and a listener on it is ordinary code that can be broken like any other. It
        // must not replace the failure being reported — an observer is not part of the
        // operation — and it must not disappear either: a listener that throws on every
        // record is silently doing nothing, which looks exactly like an audit log with
        // no failures in it.
        $this->gateway->failWith = new \RuntimeException('the cluster went away');

        $writer = $this->writer(null, FailurePolicy::Log, self::listener(static function (object $event): void {
            if ($event instanceof RecordFailedEvent) {
                throw new \RuntimeException('the listener is broken');
            }
        }));

        $writer->write(new AuditRecord('order', 1, AuditEvent::UPDATE, changes: ['status' => new Change('a', 'b')]));

        $said = implode("\n", $this->logged);

        self::assertStringContainsString('A listener of RecordFailedEvent threw', $said, 'a broken listener was never mentioned');
        self::assertStringNotContainsString('{reason}', $said, 'the line was logged with its placeholder still in it');
    }

    private static function brokenComparator(): ValueComparatorInterface
    {
        return new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('a comparator written elsewhere');
            }
        };
    }

    private static function listener(callable $listener): EventDispatcherInterface
    {
        return new class($listener) implements EventDispatcherInterface {
            /** @var callable */
            private $listener;

            public function __construct(callable $listener)
            {
                $this->listener = $listener;
            }

            public function dispatch(object $event): object
            {
                ($this->listener)($event);

                return $event;
            }
        };
    }

    private function writer(?FrameBuffer $buffer, FailurePolicy $policy, ?EventDispatcherInterface $events = null): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), [], $policy, $this->logger(), $events, $buffer);
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
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                // Interpolated the way a PSR-3 handler does: a placeholder with nothing
                // in the context to fill it is written out as itself, which is exactly
                // the failure this file is watching for.
                $filled = (string) $message;

                foreach ($context as $key => $value) {
                    if (\is_scalar($value) || $value instanceof \Stringable) {
                        $filled = str_replace('{'.$key.'}', (string) $value, $filled);
                    }
                }

                $this->logged[] = $filled;
            }
        };
    }
}
