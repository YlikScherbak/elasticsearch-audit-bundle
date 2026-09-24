<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Vehicle;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;

/**
 * The row memory as the listener keeps it, with the log it reads letting go of what it read.
 *
 * Nothing the listener writes is decided from either yet; this is what they hold, when they
 * are settled, and what it costs -- the first step of phase 3, which changes no history.
 */
final class WhatTheListenerRemembersTest extends DoctrineTestCase
{
    private StatementLog $log;

    public function testWhatAFlushWroteIsSettledOnceItIsPublishedAndTheLogLetsGo(): void
    {
        $this->log = $this->watchTheConnection(FailurePolicy::Log, letsGo: true);
        $x = $this->aLine();

        $x->quantity = 2;
        $this->em->flush();

        self::assertSame(2, $this->remembered($x), 'what the row holds, folded in');
        self::assertNull($this->log->statement($this->log->position()), 'and the log let go of it');
        self::assertSame([], $this->memory()->replayed($this->em)->facts(), 'with nothing left to replay');
    }

    public function testSettlingDoesNotWaitForThePublishingToSucceed(): void
    {
        // The row is a fact about the database, and the transaction committed: a transport
        // that refuses the history changes nothing about what the row holds, and a log held
        // for the next flush would be replayed into it a second time.
        $this->log = $this->watchTheConnection(FailurePolicy::Throw, letsGo: true);
        $x = $this->aLine();

        $this->gateway->failWith = new \RuntimeException('the cluster is down');
        $x->quantity = 2;

        try {
            $this->em->flush();
            self::fail('the premise: publishing fails and says so');
        } catch (\Throwable) {
        }

        $this->gateway->failWith = null;

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT quantity FROM CrateItem WHERE id = ?', [$x->id]), 'the premise: the row was written');
        self::assertSame(2, $this->remembered($x));
        self::assertNull($this->log->statement($this->log->position()));

        $this->gateway->documents = [];
        $x->quantity = 3;
        $this->em->flush();

        self::assertSame(3, $this->remembered($x), 'and the next flush starts from there');
    }

    public function testNothingIsSettledInsideTheApplicationsOwnTransaction(): void
    {
        $this->log = $this->watchTheConnection(FailurePolicy::Log, letsGo: true);
        $x = $this->aLine();
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $x->quantity = 2;
        $this->em->flush();

        self::assertSame(1, $this->remembered($x), 'the application may still roll it back');
        self::assertNotNull($this->log->statement($this->log->position()), 'so the log holds it');

        $connection->rollBack();
        $this->em->flush(); // the next flush of the manager is where it is settled

        self::assertSame(1, $this->remembered($x), 'and what was rolled back never was');
    }

    public function testSqlTheApplicationRunsItselfIsOutsideTheHistoryAndSaidToBeWithoutWhatItHeld(): void
    {
        // $connection->update() writes the persister's own shape, so the log binds it to the
        // row. No flush ran it, and the bundle audits flushes: it is not in the history, and
        // the listener says so -- in its log, since it is nobody's failure -- by class, field
        // and the key's columns, and never by a value. The row moved all the same, and the
        // next flush's change starts where the application left it, not where Doctrine
        // remembers.
        $this->unownedStatementsAreExpected = true;
        $this->log = $this->watchTheConnection(FailurePolicy::Throw, letsGo: true);
        $x = $this->aLine();

        $this->em->getConnection()->update('CrateItem', ['quantity' => 5], ['id' => $x->id]);
        $this->gateway->documents = [];

        $x->quantity = 2;
        $this->em->flush();

        $said = array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'so it is not in the history')));

        self::assertSame(['A statement changed quantity of a '.CrateItem::class.' row, keyed by id, outside every flush, so it is not in the history: SQL the application ran itself, which the bundle does not audit.'], $said);
        self::assertSame([['items.'.$x->id.'.quantity' => ['old' => 5, 'new' => 2]]], array_map(static fn (array $d): array => array_filter($d['changes'], static fn (string $k): bool => str_starts_with($k, 'items.'), \ARRAY_FILTER_USE_KEY), $this->documents()));
    }

    public function testAFlushOfNothingAuditedRemembersNothing(): void
    {
        $this->log = $this->watchTheConnection(FailurePolicy::Log, letsGo: true);
        $this->em->persist($vehicle = new Vehicle());
        $this->em->flush();

        $vehicle->plate = 'BB-2';
        $this->queries = [];
        $this->em->flush();

        self::assertSame(0, $this->memory()->size(), 'a row no history is written about is not copied');
        self::assertSame(['UPDATE Vehicle SET plate = ? WHERE id = ?'], array_values(array_filter($this->queries, static fn (string $sql): bool => !str_starts_with($sql, 'SELECT'))), 'and not a statement more is run');
    }

    private function aLine(): CrateItem
    {
        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($x = new CrateItem('SKU-X'));
        $this->em->flush();

        return $x;
    }

    private function remembered(CrateItem $line): int
    {
        return (int) ($this->memory()->rows()[CrateItem::class][(string) $line->id]['quantity'] ?? 0);
    }

    private function memory(): RowMemory
    {
        foreach ($this->em->getEventManager()->getListeners(Events::onFlush) as $listener) {
            if ($listener instanceof AuditSubscriber) {
                $memory = (new \ReflectionProperty(AuditSubscriber::class, 'rows'))->getValue($listener);
                self::assertInstanceOf(RowMemory::class, $memory);

                return $memory;
            }
        }

        self::fail('the premise: the listener is attached');
    }
}
