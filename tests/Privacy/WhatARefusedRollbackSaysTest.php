<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Borsche\ElasticsearchAuditBundle\Outbox\AuditTransaction;
use Borsche\ElasticsearchAuditBundle\Outbox\OutboxContext;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailureDetails;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The last thing said when a transaction cannot even be undone.
 *
 * An audit transaction that fails rolls both halves back, and a rollback that fails too
 * is reported rather than raised: the caller needs the reason the operation failed, and
 * a database that cannot roll back is a second problem rather than the answer to the
 * first. What goes into that report is the question here.
 *
 * Two foreign exceptions meet on this path, and both were written by somebody else. The
 * operation's own — which is whatever the application threw, and an application throws
 * exceptions that quote values — and the driver's, which quotes the statement it could
 * not undo. `redact.failure_details` exists to say whether messages like those are
 * repeated in what the bundle logs, and its default says they are not: a value a record
 * was redacted of must not arrive in a log line by another road.
 *
 * EveryChannelSweepTest walks the writer's roads. This one is the road that only opens
 * when two things fail at once, which is exactly when nobody is watching the log they
 * are about to fill.
 */
final class WhatARefusedRollbackSaysTest extends TestCase
{
    private const MARKER = 'LEAK_MARKER_rollback_3f9c';

    /** @var list<array{string, array<mixed, mixed>}> */
    private array $logs = [];

    public function testNeitherFailureIsQuotedIntoTheLog(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for a connection to fail rolling back.');
        }

        $transaction = $this->transactionThatCannotRollBack();

        try {
            $transaction->run(function (): void {
                // What an application throws, which is not this bundle's text and may
                // quote anything the application was holding.
                throw new \RuntimeException('the operation failed while handling '.self::MARKER);
            });

            self::fail('the operation should have failed');
        } catch (\Throwable $thrown) {
            // The caller is told, and told the truth: this is the reason the operation
            // failed, not the reason the rollback did.
            self::assertStringContainsString(self::MARKER, $thrown->getMessage(), 'the premise: the caller keeps the cause it threw');
        }

        self::assertNotSame([], $this->logs, 'the premise: the refused rollback was reported');

        $said = (string) json_encode($this->logs, \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);

        self::assertStringNotContainsString(self::MARKER, $said, sprintf("the marker reached the log:\n%s", $said));
    }

    public function testTheFrameIsDroppedEvenIfTheLoggerItselfFails(): void
    {
        // The frame holds the records of an operation that is being undone, and a frame
        // left holding them hands them to whatever runs next. Reporting the refused
        // rollback happens first, and a logger is somebody else's code: one that throws
        // must not be the reason a rolled-back operation's history is published under
        // the next one.
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for a connection to fail rolling back.');
        }

        $buffer = new FrameBuffer();
        $writer = self::writer($buffer);
        $frame = new AuditFrame($buffer, $writer);

        $transaction = $this->transactionThatCannotRollBack($frame, new class extends AbstractLogger {
            /**
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('the logger is down too');
            }
        });

        try {
            $transaction->run(static function () use ($writer): void {
                // Held by the frame, which is the whole point: an operation that records
                // something and then fails is what leaves a frame with something in it.
                $writer->record('order', 1, 'update', ['status' => new Change('draft', 'paid')]);

                throw new \RuntimeException('the operation failed');
            });
        } catch (\Throwable) {
            // Whichever of the three failures comes out, the frame has to be empty.
        }

        self::assertSame(0, $buffer->count(), 'the frame still holds the records of an operation that was undone');
    }

    public function testAskingForFullDetailStillRepeatsBoth(): void
    {
        // The other half of the setting, and the reason it is a setting: an application
        // that has declared no sensitive fields and wants the driver's own words says so
        // explicitly, and then gets them — including the exception itself, which is what
        // an exception logger unwinds.
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for a connection to fail rolling back.');
        }

        $transaction = $this->transactionThatCannotRollBack(failureDetails: FailureDetails::Full);

        try {
            $transaction->run(static function (): void {
                throw new \RuntimeException('the operation failed while handling '.self::MARKER);
            });
        } catch (\Throwable) {
        }

        $said = (string) json_encode($this->logs, \JSON_PARTIAL_OUTPUT_ON_ERROR);

        self::assertStringContainsString(self::MARKER, $said, 'full means full, and this is the configuration that asks for it');
    }

    public function testALoggerThatFailsIsNotTheAnswerTheCallerGets(): void
    {
        // Three failures in a row now: the operation, the rollback, and the logger. The
        // caller asked about the first one. "Your logger is down" is not an answer to
        // "why did my operation fail", and it is the answer they would get if reporting
        // the second failure were allowed to raise the third.
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for a connection to fail rolling back.');
        }

        $transaction = $this->transactionThatCannotRollBack(logger: new class extends AbstractLogger {
            /**
             * @param mixed               $level
             * @param mixed               $message
             * @param array<mixed, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('the logger is down too');
            }
        });

        try {
            $transaction->run(static function (): void {
                throw new \DomainException('the operation itself failed');
            });

            self::fail('the operation should have failed');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf(\DomainException::class, $thrown, 'the caller was handed the wrong failure: '.$thrown->getMessage());
            self::assertSame('the operation itself failed', $thrown->getMessage());
        }
    }

    public function testTheNextOperationGetsNoneOfTheUndoneOnes(): void
    {
        // The frame being empty is the mechanism; this is what it is for. An operation
        // that was rolled back has no history, and the one after it must not publish the
        // records of an operation that never happened under its own name.
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for a connection to fail rolling back.');
        }

        $gateway = new InMemoryGateway();
        $buffer = new FrameBuffer();
        $transport = new SyncTransport($gateway);
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), frame: $buffer);
        $frame = new AuditFrame($buffer, $writer);

        try {
            $this->transactionThatCannotRollBack($frame)->run(static function () use ($writer): void {
                $writer->record('order', 1, 'update', ['status' => new Change('draft', 'undone')]);

                throw new \RuntimeException('the operation failed');
            });
        } catch (\Throwable) {
        }

        // A perfectly ordinary operation afterwards, through the same frame.
        $frame->coalesce(static function () use ($writer): void {
            $writer->record('order', 2, 'update', ['status' => new Change('draft', 'paid')]);
        });

        $written = $gateway->documents['audit_log'] ?? [];

        self::assertCount(1, $written, 'the next operation published the records of one that was undone');
        self::assertSame(2, $written[0]['objectId']);
    }

    private function transactionThatCannotRollBack(?AuditFrame $frame = null, ?AbstractLogger $logger = null, FailureDetails $failureDetails = FailureDetails::Cause): AuditTransaction
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new class implements Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    /**
                     * @param array<string, mixed> $params
                     */
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function rollBack(): void
                            {
                                // What a driver says when it cannot undo: the statement,
                                // quoted, and its own cause behind it.
                                throw new \RuntimeException(
                                    'could not roll back after INSERT INTO audit_outbox ... '.WhatARefusedRollbackSaysTest::marker(),
                                    0,
                                    new \RuntimeException('the connection is gone: '.WhatARefusedRollbackSaysTest::marker()),
                                );
                            }
                        };
                    }
                };
            }
        }]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        $logs = &$this->logs;

        return new AuditTransaction(
            $connection,
            $frame ?? new AuditFrame(new FrameBuffer(), self::writer()),
            new OutboxContext(),
            $logger ?? new class($logs) extends AbstractLogger {
                /** @param list<array{string, array<mixed, mixed>}> $logs */
                public function __construct(private array &$logs)
                {
                }

                /**
                 * @param mixed               $level
                 * @param mixed               $message
                 * @param array<mixed, mixed> $context
                 */
                public function log($level, $message, array $context = []): void
                {
                    // Message and context together, with any exception in the context
                    // unwound into its whole chain: that is what a log handler writes.
                    $this->logs[] = [(string) $message, array_map(self::readable(...), $context)];
                }

                private static function readable(mixed $value): mixed
                {
                    if (!$value instanceof \Throwable) {
                        return $value;
                    }

                    $chain = [];

                    for ($link = $value; $link !== null; $link = $link->getPrevious()) {
                        $chain[] = $link::class.': '.$link->getMessage();
                    }

                    return $chain;
                }
            },
            null,
            $failureDetails,
        );
    }

    /**
     * A writer the frame can hold records for, wired to nothing: this test is about the
     * road the frame takes when the operation is undone, and nothing is ever written.
     */
    private static function writer(?FrameBuffer $buffer = null): AuditWriter
    {
        $transport = new SyncTransport(new InMemoryGateway());

        return new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'tests'), new FrozenClock(), frame: $buffer);
    }

    public static function marker(): string
    {
        return self::MARKER;
    }
}
