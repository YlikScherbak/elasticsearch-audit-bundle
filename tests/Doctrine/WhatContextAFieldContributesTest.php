<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Consignment;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * `alwaysRecord`, and which value ends up beside the change.
 *
 * The fields it names are written into every record whether they moved or not, so a
 * history line reads on its own: "status was shipped when the note changed" answers a
 * question that "the note changed" cannot. That makes the value they carry a statement
 * about the row, and a wrong one is worse than none — it describes a state nobody can
 * find in the database.
 *
 * Two ways to get it wrong, and mutation testing found nothing standing against either.
 * The gathering can stop at the first field it has nothing to say about instead of
 * carrying on to the next. And it can read the value off the object, which is not the
 * value the row got: a postUpdate listener touching the entity changes nothing in the
 * database, and a preUpdate one changes the row without the object ever being asked.
 */
final class WhatContextAFieldContributesTest extends DoctrineTestCase
{
    public function testTheFieldBehindTheSkippedOneIsStillRecorded(): void
    {
        // status is audited, so a change to it is already in the record and the context
        // pass has nothing to add for it. carrier comes after it and does: stopping at
        // the skip instead of carrying on loses every always-recorded field behind the
        // first one that happened to move.
        $consignment = new Consignment('packed');
        $consignment->carrier = 'dhl';
        $this->em->persist($consignment);
        $this->em->flush();

        $this->gateway->documents = [];

        $consignment->status = 'shipped';
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'packing', 'new' => 'shipped'], $changes['status'] ?? null, 'the premise: status moved, so it is a change and not context');
        self::assertSame(['old' => 'dhl', 'new' => 'dhl'], $changes['carrier'] ?? null, 'the field behind it is context on the same record');
    }

    public function testTheContextIsWhatTheRowGotAndNotWhatTheObjectEndedWith(): void
    {
        // Three values for one field in one flush, which is what makes the question
        // readable at all. The object arrives holding the first, the application sets
        // the second and the row gets it, and a postUpdate listener leaves a third on
        // the object afterwards. Only the second is in the database.
        //
        // The field reaches the context pass because a comparator says it did not really
        // change — which drops it from the changes while leaving it in Doctrine's change
        // set, and is the ordinary shape of "100 and 100.0 are the same number".
        $this->attachListener(FailurePolicy::Log, new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                return $field === 'carrier' ? true : null;
            }
        });

        $consignment = new Consignment('packed');
        $consignment->carrier = 'what the object arrived with';
        $this->em->persist($consignment);
        $this->em->flush();

        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class {
            public function postUpdate(LifecycleEventArgs $args): void
            {
                $entity = $args->getObject();

                if ($entity instanceof Consignment) {
                    // After the UPDATE: this reaches the object and not the database.
                    $entity->carrier = 'what the object ended with';
                }
            }
        });

        $this->gateway->documents = [];

        $consignment->carrier = 'what the row got';
        $consignment->note = 'amended';
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'packed', 'new' => 'amended'], $changes['note'] ?? null, 'the premise: something moved, so there is a record to give context to');
        self::assertSame(
            ['old' => 'what the row got', 'new' => 'what the row got'],
            $changes['carrier'] ?? null,
            'the context describes the row, not what the object arrived with and not what it ended with',
        );
    }

    public function testTheContextIsWhatAPreUpdateListenerCorrectedItTo(): void
    {
        // The other half of "the context describes the row". A postUpdate listener
        // reaches the object after the UPDATE and so must be ignored; a preUpdate one
        // reaches the row *instead of* the object — Doctrine recomputes the change set
        // and writes the corrected value — and so must be believed.
        //
        // Read off the snapshot this field held when the flush began, the record would
        // name the value that was planned a moment earlier and never stored. Read off
        // the object it would be right here by accident, because a preUpdate correction
        // happens to change both; the postUpdate test next door is the one that tells
        // those two apart.
        $this->attachListener(FailurePolicy::Log, new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                // Which is how carrier reaches the context pass at all: dropped from the
                // changes, still in Doctrine's change set.
                return $field === 'carrier' ? true : null;
            }
        });

        $consignment = new Consignment('packed');
        $consignment->carrier = 'first';
        $this->em->persist($consignment);
        $this->em->flush();

        $this->em->getEventManager()->addEventListener([Events::preUpdate], new class {
            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $entity = $args->getObject();

                if (!$entity instanceof Consignment || $entity->carrier !== 'planned') {
                    return;
                }

                // Before the UPDATE, so this is the value the row takes.
                $entity->carrier = 'corrected';

                $em = $args->getObjectManager();
                $em->getUnitOfWork()->recomputeSingleEntityChangeSet($em->getClassMetadata(Consignment::class), $entity);
            }
        });

        $this->gateway->documents = [];

        $consignment->carrier = 'planned';
        $consignment->note = 'amended';
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'packed', 'new' => 'amended'], $changes['note'] ?? null, 'the premise: something moved, so there is a record to give context to');
        self::assertSame(
            ['old' => 'corrected', 'new' => 'corrected'],
            $changes['carrier'] ?? null,
            'the context names the value that was only planned',
        );
    }
}
