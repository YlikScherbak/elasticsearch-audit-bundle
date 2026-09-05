<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Shipment;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\ShipmentLine;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * What the unit of work knows, and when. Tracking changes inside the elements of a
 * collection rests on both answers, and on Doctrine's behaviour rather than on
 * reasoning about it — so they are pinned here, for both supported ORM majors.
 */
final class UnitOfWorkTimingTest extends DoctrineTestCase
{
    /** @var list<array{owner: string, scheduled: list<string>}> */
    private array $seen = [];

    public function testScheduledUpdatesAreStillThereWhenPostUpdateRuns(): void
    {
        $shipment = $this->shipmentWithTwoLines();
        $this->watchPostUpdate();

        $shipment->reference = 'SH-2';                 // the owner is dirty...
        $shipment->lines->first()->quantity = 7;       // ...and so is one of its lines
        $this->em->flush();

        $owners = array_column($this->seen, 'owner');
        self::assertContains(Shipment::class, $owners, 'the owner got its postUpdate');

        $atShipment = array_values(array_filter($this->seen, static fn (array $s) => $s['owner'] === Shipment::class))[0];

        self::assertContains(ShipmentLine::class, $atShipment['scheduled'], 'the changed line is still in getScheduledEntityUpdates()');
    }

    public function testAnUntouchedOwnerGetsNoPostUpdateAtAll(): void
    {
        $shipment = $this->shipmentWithTwoLines();
        $this->watchPostUpdate();

        // Only the line changes. Doctrine has nothing to UPDATE on the shipment, so it
        // raises no event for it: an owner's record cannot be built from postUpdate alone.
        $shipment->lines->first()->quantity = 9;
        $this->em->flush();

        self::assertSame([ShipmentLine::class], array_column($this->seen, 'owner'));
    }

    public function testTheChangeSetOfAnElementIsReadableFromTheOwnersEvent(): void
    {
        $shipment = $this->shipmentWithTwoLines();
        $this->watchPostUpdate();

        $shipment->reference = 'SH-3';
        $shipment->lines->first()->quantity = 4;
        $this->em->flush();

        self::assertSame(['quantity' => [1, 4]], $this->lineChangeSet);
    }

    public function testAFlushInsideAnotherListenerDoesNotEmptyTheRecord(): void
    {
        $shipment = $this->shipmentWithTwoLines();

        // A listener of somebody else's, minding its own business, that saves something
        // of its own from postUpdate. UnitOfWork::commit() ends in postCommitCleanup(),
        // which empties entityChangeSets — of THIS flush too, which is still running.
        // Whatever reads a change set after this point finds nothing.
        $this->flushFromPostUpdate();
        $this->attachListener(FailurePolicy::Log);

        $shipment->reference = 'SH-9';
        $this->em->flush();

        self::assertSame(
            ['reference' => ['old' => 'SH-1', 'new' => 'SH-9']],
            $this->lastDocument()['changes'],
            'the change survives a nested flush: it was taken in onFlush, not read back in postUpdate'
        );
    }

    public function testAFlushInsideAnotherListenerDoesNotEmptyACreateEither(): void
    {
        // The same wipe, one door over: postPersist instead of postUpdate. Without
        // the insertions half of the snapshot the history said an entity appeared
        // with no values at all — and a create has no skip_empty_updates to hide
        // behind, so the empty record was written.
        $this->flushFromPostPersist();
        $this->attachListener(FailurePolicy::Log);

        $shipment = new Shipment('SH-BORN');
        $this->em->persist($shipment);
        $this->em->flush();

        $creates = array_values(array_filter(
            $this->gateway->documents['audit_log'] ?? [],
            static fn (array $d): bool => $d['event'] === 'create',
        ));

        self::assertCount(1, $creates, 'one entity, one create');
        self::assertSame(['old' => null, 'new' => 'SH-BORN'], $creates[0]['changes']['reference'], 'born with its values, not empty-handed');
    }

    public function testANestedFlushDoesNotPublishTheOuterFlushsRecordsEarly(): void
    {
        // The mirror of the case already covered: here the audit listener runs FIRST,
        // so the outer record is already pending when another listener flushes. The
        // inner postFlush() used to take the whole pending list — records belonging to
        // a transaction that has not committed yet — and write them. If the outer
        // flush then fails, the history describes what the database rolled back.
        $shipment = $this->shipmentWithTwoLines();

        $nestingAtWrite = [];
        $connection = $this->em->getConnection();
        $this->gateway->onIndex = static function () use (&$nestingAtWrite, $connection): void {
            $nestingAtWrite[] = $connection->getTransactionNestingLevel();
        };

        // Registered after the audit listener, so its flush happens while the outer
        // flush is still inside its own transaction.
        $this->flushFromPostUpdate();

        $shipment->reference = 'SH-NESTED';
        $this->em->flush();

        self::assertNotSame([], $nestingAtWrite, 'the record was written at all');
        self::assertSame([0], array_unique($nestingAtWrite), 'and only after the transaction it belongs to had committed');
    }

    public function testANestedFlushDoesNotConsumeTheOuterFlushsElementChanges(): void
    {
        // elementChanges are collected in onFlush and folded in at postFlush. An inner
        // flush emptying them leaves the outer owner's record without the changes its
        // lines made — the record is written, and it is wrong rather than missing.
        $shipment = $this->shipmentWithTwoLines();
        $this->flushFromPostUpdate();

        $shipment->reference = 'SH-BOTH';
        $shipment->lines->first()->quantity = 9;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];
        $lineId = $shipment->lines->first()->id;

        self::assertSame(['old' => 'SH-1', 'new' => 'SH-BOTH'], $changes['reference']);
        self::assertSame(['old' => 1, 'new' => 9], $changes['lines.'.$lineId.'.quantity'], 'what the line did belongs to the same record');
    }

    public function testTheLostChangeSetIsReportedOnce(): void
    {
        $shipment = $this->shipmentWithTwoLines();

        $this->flushFromPostUpdate();
        $this->attachListener(FailurePolicy::Log);

        $shipment->reference = 'SH-10';
        $shipment->lines->first()->quantity = 3;
        $this->em->flush();

        $lost = array_values(array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, 'had no change set left')
        ));

        self::assertCount(1, $lost, 'said once per flush, not once per entity');
        self::assertStringContainsString('postCommitCleanup', $lost[0], 'names the mechanism');
        self::assertStringContainsString('Move that work to postFlush', $lost[0], 'says what to do');
    }

    public function testNothingIsReportedWhenNobodyFlushes(): void
    {
        $shipment = $this->shipmentWithTwoLines();
        $this->attachListener(FailurePolicy::Log);

        $shipment->reference = 'SH-11';
        $this->em->flush();

        self::assertSame([], array_values(array_filter(
            $this->logs,
            static fn (string $line): bool => str_contains($line, 'had no change set left')
        )), 'nothing lost, nothing to say');
    }

    /**
     * A change set taken in onFlush must not hide what a preUpdate listener did after
     * it: Doctrine merges such a change in through recomputeSingleEntityChangeSet(),
     * and the record has to say what was actually written.
     */
    public function testAChangeMadeInPreUpdateStillReachesTheRecord(): void
    {
        $shipment = $this->shipmentWithTwoLines();

        $listener = new class {
            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $entity = $args->getObject();

                if ($entity instanceof Shipment && $entity->reference !== 'SH-CORRECTED') {
                    $entity->reference = 'SH-CORRECTED';
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::preUpdate], $listener);
        $this->attachListener(FailurePolicy::Log);

        $shipment->reference = 'SH-12';
        $this->em->flush();

        self::assertSame('SH-CORRECTED', $this->lastDocument()['changes']['reference']['new']);
    }

    /**
     * The two defences meeting each other: a preUpdate listener corrects a value after
     * the onFlush snapshot was taken, and another listener then flushes, which empties
     * the unit of work's change sets — of this flush too. The record is then built from
     * the snapshot alone, and the snapshot predates the correction.
     *
     * What the database holds is the corrected value. A record saying otherwise is the
     * one thing worse than a record that says nothing: it is evidence, and it is wrong.
     */
    public function testACorrectionInPreUpdateSurvivesAFlushThatWipesTheChangeSets(): void
    {
        $shipment = $this->shipmentWithTwoLines();

        $corrects = new class {
            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $entity = $args->getObject();

                if ($entity instanceof Shipment && $entity->reference !== 'SH-CORRECTED') {
                    $entity->reference = 'SH-CORRECTED';
                }
            }
        };

        $em = $this->em;
        $flushes = new class($em) {
            private bool $done = false;

            public function __construct(private readonly EntityManagerInterface $em)
            {
            }

            public function postUpdate(\Doctrine\ORM\Event\PostUpdateEventArgs $args): void
            {
                if ($this->done) {
                    return;
                }

                $this->done = true;
                // Somebody else's listener, doing what Doctrine warns against — and
                // doing it after the row was written, where the change set this record
                // is built from is still needed. Registered before the audit listener,
                // so postUpdate reaches it first.
                $this->em->flush();
            }
        };

        $this->em->getEventManager()->addEventListener([Events::preUpdate], $corrects);
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $flushes);
        $this->attachListener(FailurePolicy::Log);

        $shipment->reference = 'SH-12';
        $this->em->flush();

        self::assertSame('SH-CORRECTED', $this->lastDocument()['changes']['reference']['new'], 'the record says what the database took');
    }

    private function flushFromPostPersist(): void
    {
        $em = $this->em;

        $listener = new class($em) {
            private bool $done = false;

            public function __construct(private readonly EntityManagerInterface $em)
            {
            }

            public function postPersist(\Doctrine\ORM\Event\PostPersistEventArgs $args): void
            {
                if ($this->done) {
                    return;
                }

                $this->done = true;
                $this->em->flush();
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postPersist], $listener);
    }

    private function flushFromPostUpdate(): void
    {
        $em = $this->em;

        $listener = new class($em) {
            private bool $done = false;

            public function __construct(private readonly EntityManagerInterface $em)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                if ($this->done) {
                    return;
                }

                $this->done = true;
                $this->em->flush(); // the whole problem, in one line
            }
        };

        // Before the audit listener: that is what makes it destructive.
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $listener);
    }

    /** @var array<string, mixed> */
    private array $lineChangeSet = [];

    private function shipmentWithTwoLines(): Shipment
    {
        $shipment = new Shipment('SH-1');
        $shipment->add(new ShipmentLine('widget', 1));
        $shipment->add(new ShipmentLine('gadget', 2));

        $this->em->persist($shipment);
        $this->em->flush();
        $this->gateway->documents = [];

        return $shipment;
    }

    private function watchPostUpdate(): void
    {
        $seen = &$this->seen;
        $changeSet = &$this->lineChangeSet;

        $watcher = new class($seen, $changeSet) {
            /**
             * @param list<array{owner: string, scheduled: list<string>}> $seen
             * @param array<string, mixed>                               $changeSet
             */
            public function __construct(private array &$seen, private array &$changeSet)
            {
            }

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                $uow = $args->getObjectManager()->getUnitOfWork();
                $scheduled = array_map(static fn (object $e): string => $e::class, array_values($uow->getScheduledEntityUpdates()));

                $this->seen[] = ['owner' => $args->getObject()::class, 'scheduled' => $scheduled];

                if ($args->getObject() instanceof Shipment) {
                    foreach ($uow->getScheduledEntityUpdates() as $entity) {
                        if ($entity instanceof ShipmentLine) {
                            $this->changeSet = $uow->getEntityChangeSet($entity);
                        }
                    }
                }
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postUpdate], $watcher);
    }
}
