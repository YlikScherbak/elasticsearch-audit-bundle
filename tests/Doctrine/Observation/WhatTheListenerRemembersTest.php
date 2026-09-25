<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\RowMemory;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Borsche\ElasticsearchAuditBundle\Tests\Doctrine\DoctrineTestCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
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

    public function testAStatementTheLogCannotFollowIsDoubtSaidOnceAndByClassAlone(): void
    {
        // An UPDATE of a watched table that names no row, read or not: the log cannot say
        // which rows it moved, so the history may be missing what it did, and the listener
        // says that -- in its log, once, at the reading that moves past it, by class and count
        // and never by what the statement carried. A watched collection's join table is the
        // same. What does not write a watched row is not doubt: the tables of the
        // application's own, mapped or not, read or not, and the schema's DDL.
        $this->unownedStatementsAreExpected = true;
        $this->log = $this->watchTheConnection(FailurePolicy::Throw, letsGo: true);
        $x = $this->aLine();
        $this->em->persist($vehicle = new Vehicle());
        $this->em->flush();

        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE CrateItem SET quantity = quantity + 1 WHERE sku LIKE 'SKU-SECRET%'");
        $connection->executeStatement('UPDATE CrateItem SET quantity = 0');
        $connection->executeStatement("DELETE FROM article_tag WHERE tag_id IN (SELECT id FROM Tag WHERE label = 'SECRET')");
        $connection->executeStatement("UPDATE Vehicle SET plate = 'SECRET-PLATE'");
        $connection->executeStatement("UPDATE Vehicle SET plate = 'SECRET-PLATE' WHERE plate LIKE 'SECRET%'");
        $connection->executeStatement('CREATE TABLE Scratch (id INT)');
        $connection->executeStatement('UPDATE Scratch SET id = 1');
        $connection->executeStatement("UPDATE Scratch SET id = 2 WHERE id IN (SELECT 1)");
        $connection->executeStatement('DROP TABLE Scratch');

        $doubts = fn (): array => array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'may be missing what they did')));

        $x->sku = 'SKU-X2';
        $this->em->flush();

        self::assertSame(['What the connection ran could not be followed for 3 statement(s) of '.CrateItem::class.', '.Article::class.' since the history was last written, so the history may be missing what they did.'], $doubts());
        self::assertSame([], array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'SECRET'))), 'nothing a statement carried');

        $x->sku = 'SKU-X3';
        $this->em->flush();

        self::assertCount(1, $doubts(), 'and not again at the next reading');
    }

    public function testWhatAStatementCarriedReachesNoChannelTheListenerSpeaksOn(): void
    {
        // The log holds the parameters of every statement in memory now, which is a new place
        // for a secret to be. A value in the SET of the application's own SQL -- one the log
        // cannot bind to a row, which is doubt, and one it can, outside every flush, which is
        // not in the history -- is in neither what the listener logs nor what it writes. The
        // sweep of the writer's channels (EveryChannelSweepTest) has no connection to run
        // this on; this is the same question on the seam the log opened.
        $this->unownedStatementsAreExpected = true;
        $this->log = $this->watchTheConnection(FailurePolicy::Log, letsGo: true);
        $x = $this->aLine();
        $marker = 'SECRET-'.bin2hex(random_bytes(4));

        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE CrateItem SET sku = ? WHERE sku LIKE 'SKU-%'", [$marker]);
        $connection->update('CrateItem', ['sku' => $marker.'-bound'], ['id' => $x->id]);

        $this->em->refresh($x);
        $x->quantity = 2;
        $this->em->flush();

        self::assertNotSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'may be missing what they did')), 'the premise: the unbound one was doubt');
        self::assertNotSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'so it is not in the history')), 'the premise: the bound one was said to be outside the history');
        self::assertSame([], array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, $marker))), 'nothing a statement carried is in what the listener logged');
        self::assertStringNotContainsString($marker, (string) json_encode(array_map(static fn (array $d): array => array_diff_key($d, ['changes' => true]), $this->documents())), 'nor anywhere in a document but the change that is the history');
    }

    public function testDoubtInsideTheApplicationsTransactionIsSaidOnceToo(): void
    {
        // The same, with every flush inside a transaction of the application's own: nothing
        // is settled there, so every reading replays the log from the same start and meets
        // the statement again. Said once all the same -- at the reading that moves past it.
        $this->unownedStatementsAreExpected = true;
        $this->log = $this->watchTheConnection(FailurePolicy::Throw, letsGo: true);
        $x = $this->aLine();

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement("UPDATE CrateItem SET quantity = quantity + 1 WHERE sku LIKE 'SKU-SECRET%'");

        $x->sku = 'SKU-X2';
        $this->em->flush();
        $x->sku = 'SKU-X3';
        $this->em->flush();
        $connection->commit();

        self::assertCount(1, array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'may be missing what they did')), 'once, not at every reading');
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
