<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\ActorResolverInterface;
use Borsche\ElasticsearchAuditBundle\Contract\MomentEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Psr\Clock\ClockInterface;

/**
 * The moment is settled in onFlush, which is the application's own flush.
 *
 * Everything that settling it touches is application code: a clock an application may
 * have replaced, an actor resolver reading a security token, an enricher reading the
 * request. Before this was taken in onFlush the actor was resolved inside the writer's
 * own guard, where a failure met the failure policy — and taking it earlier took it out
 * from behind that guard. A resolver that threw then killed the flush it was there to
 * describe, under `on_failure: log`, which is the setting that exists to say the
 * opposite: an audit log that can take the business operation down is worse than a gap
 * in the history.
 */
final class WhenSettlingTheMomentFailsTest extends DoctrineTestCase
{
    public function testAResolverThatThrowsDoesNotTakeTheOperationWithIt(): void
    {
        $this->actors = new class implements ActorResolverInterface {
            public function resolve(): ?string
            {
                throw new \RuntimeException('the security token store is not configured here');
            }
        };
        $this->attachListener(FailurePolicy::Log);

        $this->em->persist(new Article('Alice wrote this'));
        $this->em->flush();

        // The row is in the database, which is the point: the operation finished.
        self::assertCount(1, $this->em->getRepository(Article::class)->findAll(), 'the business flush did not survive the audit failure');

        $documents = $this->documents();

        self::assertCount(1, $documents, 'and the record was written anyway, without an actor');
        self::assertArrayHasKey('source', $documents[0]);
        self::assertNull($documents[0]['source'], 'a record was signed by somebody the resolver never returned');
        self::assertNotSame([], $this->logs, 'the failure was swallowed without a word');
    }

    public function testAndTheNextOperationIsNotLeftWithTheBrokenOnesState(): void
    {
        // The other half: fixing the crash is not enough if it leaves something behind.
        // The flush after it has its own moment, from a resolver that works.
        $resolver = new class implements ActorResolverInterface {
            public bool $broken = true;

            public function resolve(): ?string
            {
                if ($this->broken) {
                    throw new \RuntimeException('the security token store is not configured here');
                }

                return 'alice';
            }
        };

        $this->actors = $resolver;
        $this->clock = new class implements ClockInterface {
            public string $now = '2026-09-21 10:00:00';

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->now, new \DateTimeZone('UTC'));
            }
        };
        $this->attachListener(FailurePolicy::Log);

        $this->em->persist(new Article('While the resolver was down'));
        $this->em->flush();

        $resolver->broken = false;
        $this->clock->now = '2026-09-22 15:30:00';

        $this->em->persist(new Article('And once it came back'));
        $this->em->flush();

        $records = [];

        foreach ($this->documents() as $document) {
            $records[$document['changes']['title']['new'] ?? '?'] = $document;
        }

        self::assertArrayHasKey('source', $records['While the resolver was down'] ?? []);
        self::assertNull($records['While the resolver was down']['source']);
        self::assertSame('2026-09-21 10:00:00', $records['While the resolver was down']['loggedAt'] ?? null, 'the time was thrown away with the actor');

        self::assertSame('alice', $records['And once it came back']['source'] ?? null, 'the next flush inherited the broken one is answer');
        self::assertSame('2026-09-22 15:30:00', $records['And once it came back']['loggedAt'] ?? null);
    }

    public function testAResolverFailureDoesNotCancelWhatTheMomentDescribes(): void
    {
        // The moment's enrichers do not depend on who was acting, and a record dated and
        // routed with no actor is better history than one with neither.
        $this->actors = new class implements ActorResolverInterface {
            public function resolve(): ?string
            {
                throw new \RuntimeException('the security token store is not configured here');
            }
        };

        $this->attachListener(FailurePolicy::Log, null, [
            new class implements MomentEnricherInterface {
                public function describe(): array
                {
                    return ['route' => '/checkout'];
                }

                public function mapping(): array
                {
                    return ['route' => ['type' => 'keyword']];
                }
            },
        ]);

        $this->em->persist(new Article('Alice wrote this'));
        $this->em->flush();

        $document = $this->documents()[0] ?? [];

        self::assertArrayHasKey('source', $document);
        self::assertNull($document['source']);
        self::assertSame('/checkout', $document['route'] ?? null, 'the actor failing took the moment down with it');
    }

    public function testAClockThatThrowsDoesNotTakeTheOperationEither(): void
    {
        // Without a timestamp there is no moment at all, so the flush settles none and
        // the writer falls back to asking per record — inside the guard it has always
        // had. What must not happen is the exception reaching the application.
        $this->clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                throw new \RuntimeException('the clock is not available');
            }
        };
        $this->attachListener(FailurePolicy::Log);

        $this->em->persist(new Article('Alice wrote this'));
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(Article::class)->findAll(), 'the business flush did not survive a broken clock');
        self::assertNotSame([], $this->logs, 'and nothing was said about it');
    }

    public function testAClockThatThrowsUnderThrowRefusesTheOperationRatherThanTheRecord(): void
    {
        // Why the clock's failure is reported where it happens rather than left to the
        // records. Settling the moment runs in onFlush, before the transaction; the
        // per-record fallback runs in postFlush, after the commit. Under on_failure:
        // throw those are two different outcomes for the same broken clock — an
        // operation refused, or an operation committed and then complained about — and
        // the audit trail must not be the second one.
        $this->clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                throw new \RuntimeException('the clock is not available');
            }
        };
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist(new Article('Alice wrote this'));

        try {
            $this->em->flush();
            self::fail('on_failure: throw let the operation through');
        } catch (\Throwable) {
            // which is what the caller asked for
        }

        $this->reopen();

        self::assertCount(0, $this->em->getRepository(Article::class)->findAll(), 'the row was committed and the audit complained afterwards');
    }

    public function testUnderThrowTheOperationIsRefusedRatherThanCommittedWithoutHistory(): void
    {
        // The other policy, and the reason settling the moment early is not a problem
        // there: onFlush runs before the transaction, so raising refuses the operation
        // instead of leaving a committed change with no record of it.
        $this->actors = new class implements ActorResolverInterface {
            public function resolve(): ?string
            {
                throw new \RuntimeException('the security token store is not configured here');
            }
        };
        $this->attachListener(FailurePolicy::Throw);

        $this->em->persist(new Article('Alice wrote this'));

        try {
            $this->em->flush();
            self::fail('on_failure: throw let the operation through');
        } catch (\Throwable $e) {
            self::assertStringContainsString('audit', strtolower($e->getMessage()), 'the caller was told something other than that the audit failed');
        }

        $this->reopen();

        self::assertSame([], $this->documents(), 'a record was written for an operation that was refused');
    }
}
