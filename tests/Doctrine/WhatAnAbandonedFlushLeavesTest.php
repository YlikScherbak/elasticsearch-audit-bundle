<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Doctrine\ORM\Events;

/**
 * A flush that never came back, and what the next one does about it.
 *
 * postFlush is where this listener publishes, and it is not guaranteed to run: a
 * listener registered before it can throw and take the rest of the event with it, and a
 * flush aborted in onFlush never reaches it at all. Those two look identical from here —
 * a stack with a level left on it — and they need opposite treatment. One committed and
 * its records are history that has not been written yet; the other wrote nothing and its
 * records describe rows nobody has.
 *
 * Both paths end in a warning, because a trail arriving late and a trail being dropped
 * are things an operator has to know about, and both warnings say how many records they
 * are about — which is the difference between a note and an incident.
 *
 * TransactionSafetyTest covers what the application sees. This one covers what the
 * listener says and what it forgets, which is what mutation testing found nothing
 * standing behind.
 */
final class WhatAnAbandonedFlushLeavesTest extends DoctrineTestCase
{
    public function testAFlushThatCommittedWithoutReachingTheListenerIsWrittenLate(): void
    {
        // The rows are in the database. Dropping their records would be the audit trail
        // losing what actually happened, so they go out with the next flush and a
        // warning that names the priority problem behind it.
        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('Committed'));
        $this->em->flush();

        self::assertSame([], $this->documents(), 'the premise: publishing never ran for that flush');

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertSame(['Committed', 'Next'], array_map(
            static fn (array $d): mixed => $d['changes']['title']['new'],
            $this->documents(),
        ), 'the committed record is written late, before the one that carried it out');

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '1 audit record(s) are being written now, late'),
        ), sprintf("no warning said one record arrived late; what was logged:\n%s", implode("\n", $this->logs)));
    }

    public function testTheWarningCountsEveryRecordItIsAbout(): void
    {
        // Two, and the warning has to say two: an operator reading "1" for a flush that
        // left fifty records unwritten has been told the wrong size of problem.
        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('One'));
        $this->em->persist(new Article('Two'));
        $this->em->flush();

        $this->restorePostFlush($listener);

        $this->em->persist(new Article('Next'));
        $this->em->flush();

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '2 audit record(s) are being written now, late'),
        ), sprintf("the warning did not count both records; what was logged:\n%s", implode("\n", $this->logs)));
    }

    public function testAFlushWhoseManagerIsGoneHasItsRecordsDroppedAndSaysHowMany(): void
    {
        // The other ending. The records were collected, but the manager that would prove
        // they reached the database is closed or replaced — so nothing here can be shown
        // to have committed, and history that describes rows nobody has is worse than
        // history that is missing. It still has to be said out loud.
        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('Collected'));
        $this->em->persist(new Article('Also collected'));
        $this->em->flush();

        // What ManagerRegistry::resetManager() does, and the reason the flush's manager
        // is held by a weak reference at all: a fresh one takes over and the old one is
        // collected. close() would not do here — it clears, onClear drops everything the
        // flush collected, and there would be nothing left to warn about.
        $this->reopen();
        $this->restorePostFlush($listener);
        gc_collect_cycles();

        $this->em->persist(new Article('Unrelated'));
        $this->em->flush();

        self::assertSame(['Unrelated'], array_map(
            static fn (array $d): mixed => $d['changes']['title']['new'],
            $this->documents(),
        ), 'the records of a flush nothing can vouch for are not written under somebody else s operation');

        self::assertNotSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, '2 audit record(s) it had collected are dropped'),
        ), sprintf("no warning said two records were dropped; what was logged:\n%s", implode("\n", $this->logs)));
    }

    public function testAFlushThatWasDroppedIsNotDroppedTwice(): void
    {
        // Forgetting is the point of that branch: the records are gone, and so is every
        // note about the flush that collected them. Left behind, they would be offered
        // to the flush after this one as well, and the warning would repeat for a flush
        // that ended two operations ago.
        $listener = $this->silenceOurPostFlush();

        $this->em->persist(new Article('Collected'));
        $this->em->flush();

        $this->reopen();
        $this->restorePostFlush($listener);
        gc_collect_cycles();

        $this->em->persist(new Article('First after'));
        $this->em->flush();

        $this->logs = [];

        $this->em->persist(new Article('Second after'));
        $this->em->flush();

        self::assertSame([], array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, 'dropped'),
        ), 'the flush was mourned twice');
    }

    /**
     * Takes this listener off postFlush and leaves it on everything else, which is what
     * a listener registered before it and throwing does to it — for one flush. The
     * caller puts it back, because the case under test is a flush that lost its
     * publishing and a process that carries on, not a process that never publishes
     * again.
     */
    private function silenceOurPostFlush(): AuditSubscriber
    {
        foreach ($this->em->getEventManager()->getListeners(Events::postFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                $this->em->getEventManager()->removeEventListener([Events::postFlush], $listener);

                return $listener;
            }
        }

        self::fail('no audit listener was attached to postFlush');
    }

    private function restorePostFlush(AuditSubscriber $listener): void
    {
        $this->em->getEventManager()->addEventListener([Events::postFlush], $listener);
    }
}
