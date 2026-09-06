<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Coalescing\Messenger\FrameResetMiddleware;
use Borsche\ElasticsearchAuditBundle\Event\RecordCreatedEvent;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
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
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

final class AuditFrameTest extends TestCase
{
    private InMemoryGateway $gateway;
    private FrameBuffer $buffer;
    private AuditWriter $writer;
    private AuditFrame $frame;

    /** @var list<object> */
    private array $events = [];

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $this->buffer = new FrameBuffer();
        $this->writer = $this->writer(FailurePolicy::Log);
        $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());
    }

    public function testOutsideAFrameRecordsGoStraightThrough(): void
    {
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

        self::assertCount(1, $this->gateway->documents['audit_log']);
    }

    public function testInsideAFrameTheOperationIsRecordedOnceWithItsNetEffect(): void
    {
        $this->frame->coalesce(function (): void {
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1000, 1040)]);
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1040, 995)]);
            $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(10, 20)]);
            $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(20, 10)]);

            self::assertSame([], $this->gateway->documents, 'nothing is written while the frame is open');
        });

        $documents = $this->gateway->documents['audit_log'];

        self::assertCount(1, $documents, 'object 2 went back to where it started');
        self::assertSame(1, $documents[0]['objectId']);
        self::assertSame(['fact' => ['old' => 1000, 'new' => 995]], $documents[0]['changes']);
    }

    public function testTheRecordCreatedEventSeesTheCoalescedRecordNotTheSteps(): void
    {
        $this->frame->coalesce(function (): void {
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(2, 3)]);
        });

        $created = array_filter($this->events, static fn (object $e) => $e instanceof RecordCreatedEvent);

        self::assertCount(1, $created);
    }

    public function testCoalesceReturnsTheOperationsResultAndClosesOnException(): void
    {
        self::assertSame(42, $this->frame->coalesce(static fn () => 42));

        try {
            $this->frame->coalesce(function (): void {
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                throw new \RuntimeException('operation failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertFalse($this->frame->isOpen());
        self::assertCount(1, $this->gateway->documents['audit_log'], 'end() in finally still writes what was collected');
    }

    public function testImmediateWritesBypassAnOpenFrame(): void
    {
        $this->frame->begin();
        $this->writer->write(new AuditRecord('stock', 1, AuditEvent::UPDATE, changes: ['fact' => new Change(1, 2)]), immediately: true);

        self::assertCount(1, $this->gateway->documents['audit_log']);

        $this->frame->end();
    }

    public function testResetDropsAnUnclosedFrameAndWarns(): void
    {
        $this->frame->begin();
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

        self::assertTrue($this->frame->reset());
        self::assertFalse($this->frame->reset(), 'nothing left to reset');
        self::assertSame([], $this->gateway->documents, 'a reset frame writes nothing');
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('1 held record(s)', $this->warnings[0]);
    }

    public function testTheMiddlewareReleasesWhatAHandlerLeftOpen(): void
    {
        $middleware = new FrameResetMiddleware($this->frame);
        $handler = new class($this->frame, $this->writer) {
            public function __construct(private readonly AuditFrame $frame, private readonly AuditWriter $writer)
            {
            }

            public function __invoke(object $message): void
            {
                $this->frame->begin(); // no end(): the bug the middleware exists for
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                throw new \RuntimeException('handler died');
            }
        };

        try {
            $middleware->handle(self::consumed(new \stdClass()), new StackMiddleware(new HandlerMiddleware($handler)));
        } catch (\RuntimeException) {
        }

        self::assertFalse($this->frame->isOpen());
        self::assertCount(1, $this->gateway->documents['audit_log'], 'the flush that produced it committed, so the record is real history');
        self::assertCount(1, $this->warnings);

        // The next message is not affected.
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
        self::assertCount(2, $this->gateway->documents['audit_log']);
    }

    public function testADispatchFromInsideAFrameDoesNotEndIt(): void
    {
        // The middleware runs on dispatch too — it sits before SendMessageMiddleware —
        // so with the messenger transport, sending anything from inside an open frame
        // used to release the frame mid-operation: phantom intermediate states, and a
        // warning blaming a try/finally the user never omitted.
        $middleware = new FrameResetMiddleware($this->frame);

        $this->frame->begin();
        $this->writer->record('stock', 7, AuditEvent::UPDATE, ['fact' => new Change(1000, 1040)]);

        // What MessengerTransport::send() does: a dispatch, no ReceivedStamp.
        $middleware->handle(new Envelope(new \stdClass()), new StackMiddleware(new HandlerMiddleware(static function (): void {})));

        self::assertTrue($this->frame->isOpen(), 'sending a message is not the end of the operation');
        self::assertSame([], $this->gateway->documents, 'and nothing was written early');
        self::assertSame([], $this->warnings);

        $this->writer->record('stock', 7, AuditEvent::UPDATE, ['fact' => new Change(1040, 995)]);
        $this->frame->end();

        $documents = $this->gateway->documents['audit_log'];

        self::assertCount(1, $documents, 'one operation, one record');
        self::assertSame(['old' => 1000, 'new' => 995], $documents[0]['changes']['fact'], 'coalesced across the dispatch');
    }

    public function testTheMiddlewareDoesNotReplaceAHandlersExceptionWithAnAuditOne(): void
    {
        // The same rule coalesce() follows, at the other boundary: the handler failed
        // for a reason, and Messenger decides retry, failure transport and alerting by
        // that reason. Releasing a leaked frame afterwards must not overwrite it — the
        // message would be classified by the audit trail's trouble instead of its own.
        $writer = $this->writer(FailurePolicy::Throw);
        $frame = new AuditFrame($this->buffer, $writer, $this->logger());
        $middleware = new FrameResetMiddleware($frame, $this->logger());

        $handler = new class($frame, $writer) {
            public function __construct(private readonly AuditFrame $frame, private readonly AuditWriter $writer)
            {
            }

            public function __invoke(object $message): void
            {
                $this->frame->begin(); // leaked, which is why the middleware acts at all
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

                throw new \DomainException('the handler failed');
            }
        };

        $this->gateway->failWith = new \RuntimeException('cluster down');

        try {
            $middleware->handle(self::consumed(new \stdClass()), new StackMiddleware(new HandlerMiddleware($handler)));
            self::fail('the handler exception should have surfaced');
        } catch (\DomainException $e) {
            self::assertSame('the handler failed', $e->getMessage());
        }

        self::assertFalse($frame->isOpen(), 'and the frame was still closed');
        self::assertNotEmpty(array_filter($this->warnings, static fn (string $m) => str_contains($m, 'could not be released')));
    }

    public function testAFailedReleaseStillSurfacesWhenTheHandlerSucceeded(): void
    {
        // Nothing to mask here: the operation went through, so a failed audit write
        // under "throw" is the only thing that went wrong and the caller should hear it.
        $writer = $this->writer(FailurePolicy::Throw);
        $frame = new AuditFrame($this->buffer, $writer, $this->logger());
        $middleware = new FrameResetMiddleware($frame);

        $handler = new class($frame, $writer) {
            public function __construct(private readonly AuditFrame $frame, private readonly AuditWriter $writer)
            {
            }

            public function __invoke(object $message): void
            {
                $this->frame->begin();
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            }
        };

        $this->gateway->failWith = new \RuntimeException('cluster down');

        $this->expectException(WriteFailedException::class);

        $middleware->handle(self::consumed(new \stdClass()), new StackMiddleware(new HandlerMiddleware($handler)));
    }

    public function testANestedConsumeReleasesOnlyAtTheOutermostBoundary(): void
    {
        // A handler consuming one message can consume another synchronously; the frame
        // it opened belongs to the outer handler and ends when the outer handler does.
        $middleware = new FrameResetMiddleware($this->frame);

        $inner = new HandlerMiddleware(static function (): void {
            // unrelated work; the point is the boundary it creates on the way out
        });

        $outer = new HandlerMiddleware(function () use ($middleware, $inner): void {
            $this->frame->begin();
            $this->writer->record('stock', 7, AuditEvent::UPDATE, ['fact' => new Change(1000, 1040)]);
            $middleware->handle(self::consumed(new \stdClass()), new StackMiddleware($inner));
            // the frame must still be open here, or the next step starts a record of its own
            $this->writer->record('stock', 7, AuditEvent::UPDATE, ['fact' => new Change(1040, 995)]);
            // no end(): the middleware closes what the handler left open — once, here.
        });

        $middleware->handle(self::consumed(new \stdClass()), new StackMiddleware($outer));

        $documents = $this->gateway->documents['audit_log'];

        self::assertCount(1, $documents, 'the nested consume did not cut the operation in two');
        self::assertSame(['old' => 1000, 'new' => 995], $documents[0]['changes']['fact']);
    }

    public function testAMessageRoutedToTheSyncTransportDoesNotCutTheFrameAroundIt(): void
    {
        // Symfony's SyncTransport::send() re-dispatches the envelope through the bus
        // with a ReceivedStamp('sync'), so a message handled synchronously from inside
        // an operation is indistinguishable from one a worker consumed. In a worker the
        // nesting counter saves it; in a web request nothing does — consuming goes
        // 0 → 1 → 0 and the frame is released in the middle of somebody's operation.
        // Routing IndexAuditRecords to sync:// is an ordinary dev configuration, and so
        // is dispatching a domain message from inside coalesce().
        $middleware = new FrameResetMiddleware($this->frame, $this->logger());

        $this->frame->begin();
        $this->writer->record('stock', 7, AuditEvent::UPDATE, ['fact' => new Change(1000, 1040)]);

        $middleware->handle(self::consumed(new \stdClass(), 'sync'), new StackMiddleware(new HandlerMiddleware(static function (): void {
        })));

        self::assertTrue($this->frame->isOpen(), 'the frame belongs to the operation that opened it');
        self::assertSame([], $this->gateway->documents, 'nothing was published half-way through the operation');
        self::assertSame([], $this->warnings, 'and nobody was blamed for a missing try/finally');

        $this->writer->record('stock', 7, AuditEvent::UPDATE, ['fact' => new Change(1040, 995)]);
        $this->frame->end();

        $documents = $this->gateway->documents['audit_log'];

        self::assertCount(1, $documents, 'the operation is still one record');
        self::assertSame(['old' => 1000, 'new' => 995], $documents[0]['changes']['fact']);
    }

    public function testAFailedWriteOfReleasedRecordsDoesNotLeaveTheirFailuresBehind(): void
    {
        // AuditFrame::end() and release() both drain the buffer even when the write
        // throws, so a comparator failure cannot surface inside the next operation as an
        // event about a record that operation never wrote. The writer's own release path
        // — an overflowing frame handing records back through write() — did not: under
        // on_failure: throw the report line simply never ran.
        $broken = new class implements \Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('the comparator is broken');
            }
        };

        $this->buffer = new FrameBuffer(new \Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator([$broken]), maxHeld: 1);
        $this->writer = $this->writer(FailurePolicy::Throw);
        $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());

        $this->gateway->failWith = new \RuntimeException('the cluster is down');

        $this->frame->begin();
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

        try {
            // The second object overflows a buffer of one, so the first is released and
            // written — and the write fails.
            $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(3, 4)]);
            self::fail('the write should have raised under the throw policy');
        } catch (\Throwable) {
        }

        self::assertSame([], $this->buffer->takeFinalizeFailures(), 'nothing is left to surface inside somebody else\'s operation');
    }

    public function testAGeneratorOfComparatorsIsNotExhaustedAfterTheFirstComparison(): void
    {
        // The constructors take iterable because a tagged iterator is one, which made a
        // plain Generator legal too — and the chain is walked again for every value, so
        // the second walk found it empty and agreed with nothing from then on. A
        // comparator that stops being consulted does not fail; it quietly starts letting
        // through records it exists to drop.
        $numeric = new \Borsche\ElasticsearchAuditBundle\Coalescing\NumericNullAsZeroComparator(['stock.fact']);
        $chain = new \Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator((static function () use ($numeric): \Generator {
            yield $numeric;
        })());

        self::assertTrue($chain->equals('stock', 'fact', null, 0));
        self::assertTrue($chain->equals('stock', 'fact', null, 0), 'and again, on a list somebody could only walk once');
    }

    public function testAFrameLeftOpenBeforeAConsumeIsNotFedTheNewMessagesHistory(): void
    {
        // The price of deciding ownership by "is a frame open": a frame that leaked into
        // the worker some other way — a handler bypassing the middleware, a listener
        // opening one outside a message — is treated as the caller's, so the middleware
        // leaves it alone and the new message's records join whatever it was holding.
        // The counter is what keeps the two apart in a worker, and this pins the
        // behaviour so a change to that reasoning has to face it.
        $middleware = new FrameResetMiddleware($this->frame, $this->logger());

        $this->frame->begin();
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

        $middleware->handle(self::consumed(new \stdClass()), new StackMiddleware(new HandlerMiddleware(function (): void {
            $this->writer->record('stock', 9, AuditEvent::UPDATE, ['fact' => new Change(5, 6)]);
        })));

        // Documented as it is rather than pretended away: the stale frame is still open,
        // still holding both, and the next reset() or end() decides their fate. What the
        // middleware must not do is close somebody else's frame, and it did not.
        self::assertTrue($this->frame->isOpen());
        self::assertSame([], $this->gateway->documents);

        $this->frame->end();

        self::assertCount(2, $this->gateway->documents['audit_log'], 'both are written, neither is lost');
    }

    public function testAnOverflowingOperationPublishesNothingAtAll(): void
    {
        // What on_overflow: throw means, said once here. The frame cannot keep its
        // promise — one record per object for this operation — so the operation is
        // refused, and an operation that was refused has no history. The alternative
        // reading ("write what you have and raise anyway") is what "release" already
        // does, only louder: the trail is fragmented either way, and an option that
        // gives no different guarantee has no reason to exist.
        //
        // What it cannot undo is the database: the records reached the frame because
        // their saves committed. The caller's own transaction is what rolls those back,
        // which is why this setting is only meaningful where there is one.
        $this->buffer = new FrameBuffer(maxHeld: 1, throwOnOverflow: true);
        $this->writer = $this->writer(FailurePolicy::Log);
        $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());

        try {
            $this->frame->coalesce(function (): void {
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(3, 4)]);
            });
            self::fail('the frame should have refused the second object');
        } catch (FrameOverflowException) {
        }

        self::assertSame([], $this->gateway->documents, 'a refused operation leaves no history behind');
        self::assertFalse($this->frame->isOpen(), 'and no frame either');
    }

    public function testARemoveInsideARefusedOperationIsNotPublishedEither(): void
    {
        // The hole under the test above. A remove is terminal — the frame lets it out
        // where it happens — so an operation refused three saves later had already
        // published part of itself, and with the messenger transport the message was
        // already on its way before the caller's transaction rolled anything back.
        // Under on_overflow: throw the early release is staged instead: it leaves when
        // the outermost frame closes, or never.
        $this->refusing();

        try {
            $this->frame->coalesce(function (): void {
                $this->writer->record('stock', 1, AuditEvent::REMOVE);
                $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                $this->writer->record('stock', 3, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            });
            self::fail('the frame should have refused the third object');
        } catch (FrameOverflowException) {
        }

        self::assertSame([], $this->gateway->documents, 'the remove was part of the refused operation');
    }

    public function testASecondActorInsideARefusedOperationIsNotPublishedEither(): void
    {
        // The other early release, for the same reason: a step recorded under another
        // actor ends the record before it, and that record used to go out where it
        // happened.
        $this->refusing();

        try {
            $this->frame->coalesce(function (): void {
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)], actor: 'alice');
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(2, 3)], actor: 'system');
                $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            });
            self::fail('the frame should have refused the second object');
        } catch (FrameOverflowException) {
        }

        self::assertSame([], $this->gateway->documents, "alice's record belonged to the refused operation too");
    }

    public function testWhatAnEarlyReleaseStagedStillLeavesWhenTheFrameClosesNormally(): void
    {
        // And staging is not swallowing: an operation that ends the ordinary way writes
        // everything, the staged records first — they happened before what the frame was
        // still holding, and the trail reads in the order the operation ran. With room
        // for both: max_held counts what the frame keeps back, staged records included.
        $this->refusing(maxHeld: 4);

        $this->frame->coalesce(function (): void {
            $this->writer->record('stock', 1, AuditEvent::REMOVE);
            $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
        });

        self::assertSame(
            [['1', 'remove'], ['2', 'update']],
            array_map(static fn (array $d): array => [(string) $d['objectId'], $d['event']], $this->gateway->documents['audit_log']),
        );
    }

    public function testARefusalInsideAnInnerFrameDoesNotLeaveTheOuterOneOpenAndUnwatched(): void
    {
        // reset() took every level down with it, so an application that caught the
        // refusal inside its outer frame went on recording into a frame that was no
        // longer there: the outer operation's earlier records were gone, and everything
        // after the catch was written one save at a time, outside any frame.
        //
        // The refusal covers the whole stack — those frames share one buffer and their
        // records are part of what overflowed — but the stack stays standing until its
        // own end().
        $this->refusing();
        $caught = false;

        $this->frame->coalesce(function () use (&$caught): void {
            $this->writer->record('order', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

            try {
                $this->frame->coalesce(function (): void {
                    $this->writer->record('order', 2, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                });
            } catch (FrameOverflowException) {
                $caught = true;
                self::assertTrue($this->frame->isOpen(), 'the outer frame is still the caller\'s to close');
            }

            $this->writer->record('order', 1, AuditEvent::UPDATE, ['fact' => new Change(2, 3)]);
        });

        self::assertTrue($caught, 'the inner frame should have refused');
        self::assertSame([], $this->gateway->documents, 'nothing of the refused operation reached the log, before or after the catch');
        self::assertFalse($this->frame->isOpen());
    }

    public function testAManualEndAfterARefusalWritesNothingEither(): void
    {
        // The two lifecycles have to mean the same thing. coalesce() dropped the
        // operation and begin()/end() wrote whatever had fitted — so the documented
        // try/finally pattern quietly gave a different guarantee from the closure that
        // exists to write it for you.
        $this->refusing();

        $this->frame->begin();

        try {
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(3, 4)]);
            self::fail('the frame should have refused the second object');
        } catch (FrameOverflowException) {
        } finally {
            $this->frame->end();
        }

        self::assertSame([], $this->gateway->documents);
        self::assertFalse($this->frame->isOpen());
    }

    public function testTheLeakPathDoesNotResurrectARefusedOperation(): void
    {
        // release() exists to write what a leaked frame held, because those saves went
        // through — but a refused operation is the one case where "it happened" is not
        // the question. FrameResetMiddleware calls this after every message, so without
        // it a refusal followed by a missing end() would be published by the safety net.
        $this->refusing();

        $this->frame->begin();

        try {
            $this->writer->record('stock', 1, AuditEvent::REMOVE);
            $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            $this->writer->record('stock', 3, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            self::fail('the frame should have refused the third object');
        } catch (FrameOverflowException) {
        }

        self::assertTrue($this->frame->release(), 'the frame was still open');
        self::assertSame([], $this->gateway->documents);
    }

    public function testTheFrameWorksAgainAfterARefusedOperation(): void
    {
        // The refusal belongs to the operation that overflowed, not to the process.
        $this->refusing();

        try {
            $this->frame->coalesce(function (): void {
                $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                $this->writer->record('stock', 2, AuditEvent::UPDATE, ['fact' => new Change(3, 4)]);
            });
        } catch (FrameOverflowException) {
        }

        $this->frame->coalesce(function (): void {
            $this->writer->record('stock', 3, AuditEvent::UPDATE, ['fact' => new Change(5, 6)]);
        });

        self::assertCount(1, $this->gateway->documents['audit_log']);
        self::assertSame('3', (string) $this->gateway->documents['audit_log'][0]['objectId']);
    }

    public function testStagedRecordsCountTowardsMaxHeld(): void
    {
        // The safety valve counted objects held, and staging is another way to keep a
        // record from the log. One object with alternating actors needs no new key at
        // all: every boundary ends the record before it and stages it, so max_held: 1
        // could sit on ten thousand records — the exact number the setting exists to be.
        $this->refusing(maxHeld: 3);

        try {
            $this->frame->coalesce(function (): void {
                for ($i = 0; $i < 50; ++$i) {
                    $this->writer->record('stock', 1, AuditEvent::UPDATE, ['q' => new Change($i, $i + 1)], actor: $i % 2 === 0 ? 'alice' : 'system');
                }
            });
            self::fail('the frame should have refused long before fifty records');
        } catch (FrameOverflowException) {
        }

        self::assertLessThanOrEqual(3, $this->buffer->count(), 'and it stops at the number it was given');
        self::assertSame([], $this->gateway->documents, 'a refused operation leaves no history behind');
    }

    public function testARemoveCannotGrowTheFramePastMaxHeldEither(): void
    {
        // The other early release, same valve: a remove is terminal, so each one stages
        // the record it ends plus itself.
        $this->refusing(maxHeld: 3);

        try {
            $this->frame->coalesce(function (): void {
                for ($i = 1; $i <= 20; ++$i) {
                    $this->writer->record('stock', $i, AuditEvent::REMOVE);
                }
            });
            self::fail('the frame should have refused');
        } catch (FrameOverflowException) {
        }

        self::assertSame([], $this->gateway->documents);
    }

    public function testATypeTheFrameDoesNotCoalesceWaitsWithTheRestUnderThrow(): void
    {
        // object_types says what is *merged*. It was also deciding what could be
        // published while an atomic frame was open, and the two write paths then
        // disagreed: through write() the untyped record was already in Elasticsearch when
        // the refusal came, through writeAll() it disappeared with the operation. The
        // same three records, two histories, depending on which method the caller used.
        $written = [];

        foreach (['one by one', 'as a batch'] as $how) {
            $this->buffer = new FrameBuffer(objectTypes: ['stock'], maxHeld: 1, throwOnOverflow: true);
            $this->writer = $this->writer(FailurePolicy::Log);
            $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());
            $this->gateway->documents = [];

            $records = [
                new AuditRecord('auth', 1, AuditEvent::UPDATE, changes: ['ip' => new Change('a', 'b')]),
                new AuditRecord('stock', 1, AuditEvent::UPDATE, changes: ['q' => new Change(1, 2)]),
                new AuditRecord('stock', 2, AuditEvent::UPDATE, changes: ['q' => new Change(1, 2)]),
            ];

            try {
                $this->frame->coalesce(function () use ($how, $records): void {
                    if ($how === 'as a batch') {
                        $this->writer->writeAll($records);

                        return;
                    }

                    foreach ($records as $record) {
                        $this->writer->write($record);
                    }
                });
                self::fail('the frame should have refused the second stock object');
            } catch (FrameOverflowException) {
            }

            $written[$how] = array_column($this->gateway->documents['audit_log'] ?? [], 'objectType');
        }

        self::assertSame(['one by one' => [], 'as a batch' => []], $written, 'a refused operation has no history, whichever way it was written');
    }

    public function testATypeTheFrameDoesNotCoalesceIsStillWrittenWhenTheFrameCloses(): void
    {
        // Staged, not swallowed: what the frame does not merge still leaves when the
        // operation ends the ordinary way, and it is not coalesced on the way — two
        // records for the same object of an untyped kind stay two records.
        $this->buffer = new FrameBuffer(objectTypes: ['stock'], maxHeld: 10, throwOnOverflow: true);
        $this->writer = $this->writer(FailurePolicy::Log);
        $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());

        $this->frame->coalesce(function (): void {
            $this->writer->record('auth', 1, AuditEvent::UPDATE, ['ip' => new Change('a', 'b')]);
            $this->writer->record('auth', 1, AuditEvent::UPDATE, ['ip' => new Change('b', 'c')]);
            $this->writer->record('stock', 1, AuditEvent::UPDATE, ['q' => new Change(1, 2)]);

            self::assertSame([], $this->gateway->documents, 'and nothing left while it was open');
        });

        self::assertSame(
            [['auth', ['old' => 'a', 'new' => 'b']], ['auth', ['old' => 'b', 'new' => 'c']], ['stock', ['old' => 1, 'new' => 2]]],
            array_map(
                static fn (array $d): array => [$d['objectType'], $d['changes']['ip'] ?? $d['changes']['q']],
                $this->gateway->documents['audit_log'],
            ),
        );
    }

    public function testAReleaseThatCouldNotBeWrittenLeavesNothingForTheNextMessage(): void
    {
        // The leak path at its worst, and the one scenario in this area worth a test of
        // its own: a frame somebody left open, a record staged by an actor boundary, a
        // comparator that fails at close, and a cluster that refuses the write. What the
        // buffer keeps for one operation — staged, held, and the comparator failures kept
        // for the writer — has to go with that operation even when the write throws on
        // the way out, because whatever is left here is what the NEXT message writes,
        // under its own name. That is the bug that reads afterwards as "why does this
        // request have an audit record for the one before it".
        $broken = new class implements \Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                if ($field === 'note') {
                    throw new \RuntimeException('the comparator is broken');
                }

                return null;
            }
        };

        $this->buffer = new FrameBuffer(new \Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator([$broken]), maxHeld: 4, throwOnOverflow: true);
        $this->writer = $this->writer(FailurePolicy::Throw);
        $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());

        $this->frame->begin();
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)], actor: 'alice');
        // The actor boundary ends alice's record early; under on_overflow: throw that
        // record is staged rather than written where it happened.
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(2, 3)], actor: 'bob');
        // And this one will make the comparator throw while the frame closes.
        $this->writer->record('stock', 2, AuditEvent::UPDATE, ['note' => new Change('a', 'b')], actor: 'bob');

        self::assertSame(3, $this->buffer->count(), 'one staged, two held');

        $this->gateway->failWith = new \RuntimeException('the cluster went away mid-release');

        try {
            // Nobody closed the frame: this is the safety net the middleware runs after
            // every message — and the write it does fails.
            $this->frame->release();
            self::fail('the failed write should have surfaced under the throw policy');
        } catch (WriteFailedException) {
        }

        self::assertSame([], $this->gateway->documents, 'nothing of it was written');
        self::assertFalse($this->frame->isOpen());
        self::assertSame(0, $this->buffer->count(), 'and nothing of it is still held or staged');
        self::assertSame([], $this->buffer->takeFinalizeFailures(), 'the comparator failure went with the operation it belonged to');

        // The next message, on a cluster that answers again.
        $this->gateway->failWith = null;

        $this->frame->coalesce(function (): void {
            $this->writer->record('stock', 9, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
        });

        self::assertSame([9], array_column($this->gateway->documents['audit_log'], 'objectId'), 'its own record, and only its own');
    }

    /**
     * A frame that refuses rather than releases, with room for exactly one object.
     */
    private function refusing(int $maxHeld = 1): void
    {
        $this->buffer = new FrameBuffer(maxHeld: $maxHeld, throwOnOverflow: true);
        $this->writer = $this->writer(FailurePolicy::Log);
        $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());
    }

    private static function consumed(object $message, string $transport = 'test'): Envelope
    {
        return new Envelope($message, [new ReceivedStamp($transport)]);
    }

    public function testAComparatorThatThrowsAtCloseDoesNotTakeTheFrameWithIt(): void
    {
        // held was emptied before finalize ran, so one broken comparator used to lose
        // every record of the frame — raw, past the failure policy, out of end().
        $broken = new class implements \Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                throw new \RuntimeException('the comparator is broken');
            }
        };

        $this->buffer = new FrameBuffer(new \Borsche\ElasticsearchAuditBundle\Coalescing\ValueComparator([$broken]));
        $this->writer = $this->writer(FailurePolicy::Log);
        $this->frame = new AuditFrame($this->buffer, $this->writer, $this->logger());

        $this->frame->begin();
        $this->writer->record('stock', 7, AuditEvent::UPDATE, ['fact' => new Change(1000, 1040)]);
        $this->frame->end();

        self::assertCount(1, $this->gateway->documents['audit_log'], 'the record went out, unfinalized rather than gone');

        $failed = array_filter($this->events, static fn (object $e) => $e instanceof \Borsche\ElasticsearchAuditBundle\Event\RecordFailedEvent);

        self::assertCount(1, $failed, 'and the broken comparator travels the failure policy, like on the hold() path');
    }

    public function testEnrichersRunOncePerStepAndNotAgainWhenTheFrameCloses(): void
    {
        $enricher = new class implements \Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface {
            public int $calls = 0;

            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes(['pass' => ++$this->calls]);
            }

            public function mapping(): array
            {
                return ['pass' => ['type' => 'integer']];
            }
        };

        $writer = $this->writer(FailurePolicy::Log, [$enricher]);
        $frame = new AuditFrame($this->buffer, $writer, $this->logger());

        $frame->coalesce(static function () use ($writer): void {
            $writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
            $writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(2, 3)]);
        });

        self::assertSame(2, $enricher->calls, 'a record is enriched when it enters the frame, not again when it leaves');
        self::assertSame(2, $this->gateway->only('audit_log')['pass'], 'the attributes of the latest step stand');
    }

    public function testAFrameLeftOpenIsReleasedWithAWarning(): void
    {
        $this->frame->begin();
        $this->writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);

        self::assertTrue($this->frame->release());
        self::assertFalse($this->frame->release(), 'nothing left to release');
        self::assertFalse($this->frame->isOpen());
        self::assertCount(1, $this->gateway->documents['audit_log'], 'what the frame held describes changes that did commit');
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('1 held record(s)', $this->warnings[0]);
    }

    public function testTheOperationsOwnExceptionIsNotMaskedByAFailingClose(): void
    {
        // Both went wrong: the operation threw, and writing what the frame held threw
        // too. The caller must see the first — their error handling keys off the cause
        // of the failure, not off the audit trail's trouble reporting it — and the
        // second belongs in the log, not in place of the cause.
        $writer = $this->writer(FailurePolicy::Throw);
        $frame = new AuditFrame($this->buffer, $writer, $this->logger());

        $this->gateway->failWith = new \RuntimeException('cluster down');

        try {
            $frame->coalesce(static function () use ($writer): void {
                $writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
                throw new \DomainException('the operation itself failed');
            });
            self::fail('the operation exception should have surfaced');
        } catch (\DomainException $e) {
            self::assertSame('the operation itself failed', $e->getMessage());
        }

        self::assertFalse($frame->isOpen(), 'the frame closed all the same');
        self::assertNotEmpty(
            array_filter($this->warnings, static fn (string $m) => str_contains($m, 'could not close cleanly')),
            'and the failed write is in the log rather than gone',
        );
    }

    public function testWithTheThrowPolicyAFailedWriteSurfacesFromEnd(): void
    {
        $writer = $this->writer(FailurePolicy::Throw);
        $frame = new AuditFrame($this->buffer, $writer);

        $frame->begin();
        $writer->record('stock', 1, AuditEvent::UPDATE, ['fact' => new Change(1, 2)]);
        $this->gateway->failWith = new \RuntimeException('down');

        $this->expectException(WriteFailedException::class);

        $frame->end();
    }

    /**
     * @param list<\Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface> $enrichers
     */
    private function writer(FailurePolicy $policy, array $enrichers = []): AuditWriter
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

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), $enrichers, $policy, null, $dispatcher, $this->buffer);
    }

    private function logger(): AbstractLogger
    {
        $warnings = &$this->warnings;

        return new class($warnings) extends AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings)
            {
            }

            /** @param mixed $level */
            public function log($level, $message, array $context = []): void // untyped $message: psr/log 1.x
            {
                $this->warnings[] = strtr((string) $message, ['{held}' => (string) ($context['held'] ?? '')]);
            }
        };
    }
}

/**
 * The last middleware in the test stack: calls the handler.
 */
final class HandlerMiddleware implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface
{
    public function __construct(private readonly object $handler)
    {
    }

    public function handle(Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): Envelope
    {
        ($this->handler)($envelope->getMessage());

        return $envelope;
    }
}
