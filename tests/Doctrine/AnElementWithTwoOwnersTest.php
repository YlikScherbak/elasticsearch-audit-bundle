<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Depot;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PackingCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Pallet;

/**
 * An element that belongs to two collections at once, and the walk over its
 * associations.
 *
 * One element, two owners, and each of them audits it for a different reason: the
 * pallet records which cases are on it, the depot records what changes inside one. The
 * listener finds both by walking the element's own associations — and that walk has
 * branches that finish with the association they are looking at and move on to the next.
 *
 * Every other element fixture belongs to exactly one collection, so a walk that stopped
 * after the first association it had news about would have looked identical to one that
 * carried on. Here it does not: the second owner is the one that loses its history.
 */
final class AnElementWithTwoOwnersTest extends DoctrineTestCase
{
    public function testACaseThatChangesPalletStillTellsItsDepotWhatChangedInside(): void
    {
        // The element changed hands, which Doctrine keeps on the element's own reference
        // rather than on either collection. That branch finishes with the pallet — it
        // records the case leaving one and arriving at the other — and its own fields are
        // left out of *that* association on purpose. They are not left out of the depot's:
        // the case is still in the same depot, and what changed inside it is exactly what
        // that collection is tracked for.
        $from = new Pallet('P-1');
        $to = new Pallet('P-2');
        $depot = new Depot('north');

        $case = new PackingCase('crate-1', 10);
        $from->add($case);
        $depot->add($case);

        $this->em->persist($from);
        $this->em->persist($to);
        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $case->pallet = $to;
        $to->cases->add($case);
        $from->cases->removeElement($case);
        $case->weight = 25;
        $this->em->flush();

        $byType = [];

        foreach ($this->documents() as $document) {
            $byType[$document['objectType']][] = $document['changes'];
        }

        self::assertArrayHasKey('pallet', $byType, 'the premise: the pallets heard about the move');
        self::assertArrayHasKey('depot', $byType, 'the depot is the owner behind the one the walk finished with');

        $depotChanges = $byType['depot'][0];
        $weight = $depotChanges['cases.'.$case->id.'.weight'] ?? null;

        self::assertSame(['old' => 10, 'new' => 25], $weight, 'the depot records what changed inside the case it still holds');
    }

    public function testACaseThatIsDeletedTellsBothOwnersItIsGone(): void
    {
        // A deletion answers to the owner the database row had, and it has two of them.
        // The branch that works that out finishes with the pallet and moves on; stopping
        // there leaves the depot's history saying the case is still in it.
        $pallet = new Pallet('P-1');
        $depot = new Depot('north');

        $case = new PackingCase('crate-1', 10);
        $pallet->add($case);
        $depot->add($case);

        $this->em->persist($pallet);
        $this->em->persist($depot);
        $this->em->flush();

        $id = $case->id;
        $this->gateway->documents = [];

        $this->em->remove($case);
        $this->em->flush();

        $byType = [];

        foreach ($this->documents() as $document) {
            $byType[$document['objectType']][] = $document['changes'];
        }

        self::assertSame(
            ['old' => 'crate-1', 'new' => null],
            $byType['pallet'][0]['cases.'.$id] ?? null,
            'the premise: the pallet it was on says it is gone',
        );

        self::assertSame(
            ['old' => 'crate-1', 'new' => null],
            $byType['depot'][0]['cases.'.$id] ?? null,
            'and so does the depot behind it',
        );
    }
}
