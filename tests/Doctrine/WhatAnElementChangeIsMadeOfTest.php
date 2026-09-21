<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Chute;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Depot;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Hopper;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PackingCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * What a change inside an element is built from.
 *
 * Element tracking is the part of this bundle that records what happened *inside* a
 * line of an order rather than which lines it has, and every piece of that record is
 * decided here: which fields are read, what the comparator is asked about them, and
 * whether a collection that did not move produces anything at all.
 *
 * The tests next door read the result for one field of one element, which is the shape
 * that cannot tell "read every tracked field" from "stopped at the first one with
 * nothing to say", and cannot see which question the comparator was asked.
 */
final class WhatAnElementChangeIsMadeOfTest extends DoctrineTestCase
{
    public function testAFieldWithNothingToSayDoesNotEndTheReading(): void
    {
        // The comparator is the application's, and answering "that did not really
        // change" for one field is ordinary — a rounding, a whitespace difference, a
        // number written two ways. What must not happen is the fields behind it going
        // unread: the case moved from one shelf to another and the history says nothing
        // because its weight was rounded.
        $this->attachListener(FailurePolicy::Log, new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                // The label, because it is declared before the weight and so is the
                // field the reading reaches first. Waving through the last one proves
                // nothing: there is nothing behind it to lose.
                return $field === 'cases.label' ? true : null;
            }
        });

        $depot = new Depot('north');
        $depot->add($case = new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $case->label = 'shelf-b';
        $case->weight = 25;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertArrayNotHasKey('cases.'.$case->id.'.label', $changes, 'the premise: the comparator said the label did not really move');
        self::assertSame(['old' => 10, 'new' => 25], $changes['cases.'.$case->id.'.weight'] ?? null, 'the field behind it is still read');
    }

    public function testTheComparatorIsAskedAboutTheFieldUnderItsCollection(): void
    {
        // "weight" is not the question. A depot's cases and a pallet's cases can both
        // have one and mean different things, and an application answering for a field
        // it did not mean is the comparator being asked the wrong question — the one
        // place a wrong answer is indistinguishable from a right one.
        $asked = [];
        $this->attachListener(FailurePolicy::Log, new class($asked) implements ValueComparatorInterface {
            /** @param list<string> $asked */
            public function __construct(private array &$asked)
            {
            }

            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                $this->asked[] = $objectType.'/'.$field;

                return null;
            }
        });

        $depot = new Depot('north');
        $depot->add($case = new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $asked = [];

        $case->weight = 25;
        $this->em->flush();

        self::assertContains('depot/cases.weight', $asked, sprintf("the comparator was asked about: %s", implode(', ', $asked)));
    }

    public function testACollectionThatDidNotMoveSaysNothingAboutItsElements(): void
    {
        // A record for a collection that did not change would say every element arrived,
        // or none of them did — either way a history line for an operation that never
        // touched it. The elements are still read for what changed inside them; the
        // collection itself keeps quiet.
        $depot = new Depot('north');
        $depot->add($case = new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $case->weight = 25;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 10, 'new' => 25], $changes['cases.'.$case->id.'.weight'] ?? null, 'the premise: what changed inside the case is recorded');
        self::assertArrayNotHasKey('cases', $changes, 'the collection did not change, so it has nothing to say');
    }

    public function testAnOwningCollectionIsRecordedWholeAndAsAList(): void
    {
        // The other road to "which members this has". An owning many-to-many is dirty in
        // its own right, so the record carries the collection whole — what it held and
        // what it holds — rather than one line per member arriving and leaving.
        //
        // Both sides are lists, and Doctrine's own keys are none of the reader's
        // business: after a removal from the middle they have a hole in them, and a
        // document that carries the hole is a JSON object where every other version of
        // it is an array.
        $route = new Route('R-1');
        $route->stops->add($first = new Stop('a'));
        $route->stops->add($middle = new Stop('b'));
        $route->stops->add($last = new Stop('c'));

        foreach ([$first, $middle, $last] as $stop) {
            $this->em->persist($stop);
        }

        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $route->stops->removeElement($middle);
        $this->em->flush();

        $stops = $this->lastDocument()['changes']['stops'] ?? null;

        self::assertIsArray($stops);
        self::assertSame(['a', 'b', 'c'], $stops['old'], 'what the collection held, as a list');
        self::assertSame(['a', 'c'], $stops['new'], 'and what it holds now, without the hole the removal left in the keys');
    }

    public function testAnOwningCollectionThatDidNotMoveIsNotInTheRecord(): void
    {
        // A record for a collection nobody touched would say the same list twice, which
        // is a history line for an operation that never happened to it.
        $route = new Route('R-1');
        $route->stops->add($stop = new Stop('a'));

        $this->em->persist($stop);
        $this->em->persist($route);
        $this->em->flush();

        $this->gateway->documents = [];

        $route->code = 'R-2';
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'R-1', 'new' => 'R-2'], $changes['code'] ?? null, 'the premise: something else moved');
        self::assertArrayNotHasKey('stops', $changes, 'the collection did not, so it says nothing');
    }

    public function testACorrectionToOneElementLeavesTheElementWhoseIdStartsTheSameAlone(): void
    {
        // Keys are "cases.<id>.<field>", and a correction to one element replaces that
        // element's keys before writing the new ones — "this field went back where it
        // started" has to make the planned change disappear rather than linger.
        //
        // Which keys belong to it is decided by a prefix, and a prefix without its
        // separator is the prefix of every id that starts with the same digits. Element
        // 1 correcting itself then wipes what element 11 had to say, and the history of
        // the eleventh line of an order is simply missing — no error, nothing in a log,
        // and only ever for the orders whose ids happen to line up that way.
        $depot = new Depot('north');

        for ($i = 1; $i <= 11; ++$i) {
            $depot->add(new PackingCase('shelf-'.$i, $i));
        }

        $this->em->persist($depot);
        $this->em->flush();

        $first = $depot->cases->get(0);
        $eleventh = $depot->cases->get(10);

        self::assertNotNull($first);
        self::assertNotNull($eleventh);
        self::assertSame($first->id.'1', (string) $eleventh->id, 'the premise: one id starts with the other');

        // The correction, which is what puts this element on the replacing path.
        $this->em->getEventManager()->addEventListener([Events::preUpdate], new class {
            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $entity = $args->getObject();

                if ($entity instanceof PackingCase && $entity->label === 'planned') {
                    $entity->label = 'corrected';

                    $em = $args->getObjectManager();
                    $em->getUnitOfWork()->recomputeSingleEntityChangeSet($em->getClassMetadata(PackingCase::class), $entity);
                }
            }
        });

        $this->gateway->documents = [];

        $first->label = 'planned';
        $eleventh->weight = 999;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame('corrected', $changes['cases.'.$first->id.'.label']['new'] ?? null, 'the premise: the correction reached the record');
        self::assertSame(
            ['old' => 11, 'new' => 999],
            $changes['cases.'.$eleventh->id.'.weight'] ?? null,
            'the correction to element '.$first->id.' took element '.$eleventh->id.' with it',
        );
    }

    public function testAnElementWithNothingToSayCostsTheOthersNothing(): void
    {
        // An element whose only change the comparator waved through has nothing to add,
        // and the entry it would have made is taken out again — otherwise "present but
        // empty" reads as "something changed inside", and an update with no changes at
        // all becomes a record.
        //
        // Taking it out has to mean taking out *its* entry. What the owner has collected
        // from the elements before it is not this element's to drop, and what comes
        // after it is not this element's to skip: either way the history of a line
        // nobody touched decides what is recorded about the lines that were.
        $this->attachListener(FailurePolicy::Log, new class implements ValueComparatorInterface {
            public function equals(string $objectType, string $field, mixed $old, mixed $new): ?bool
            {
                return $field === 'cases.label' ? true : null;
            }
        });

        $depot = new Depot('north');
        $depot->add($before = new PackingCase('a', 1));
        $depot->add($silent = new PackingCase('b', 2));
        $depot->add($after = new PackingCase('c', 3));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $before->weight = 100;
        $silent->label = 'renamed';  // waved through, so this element says nothing
        $after->weight = 300;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertArrayNotHasKey('cases.'.$silent->id.'.label', $changes, 'the premise: the element in the middle had nothing to say');
        self::assertSame(['old' => 1, 'new' => 100], $changes['cases.'.$before->id.'.weight'] ?? null, 'it took what the element before it had said');
        self::assertSame(['old' => 3, 'new' => 300], $changes['cases.'.$after->id.'.weight'] ?? null, 'it ended the walk, so the element after it was never read');
    }

    public function testAnAssociationOfAnElementIsPassedOverAndNotStoppedAt(): void
    {
        // What element tracking records is what changed *inside* an element, which is
        // what its own columns are reported as. An association is not that: representing
        // one needs a callable, and an element has nowhere to declare it — so it is
        // passed over.
        //
        // The chute declares its inspector before its size, which is the order Doctrine
        // reports them in, so the association is what the reading reaches first. Passing
        // over it has to mean carrying on: every column of every element that happens to
        // have an association in front of it would otherwise go unrecorded, and only for
        // the flushes where the association moved too.
        $hopper = new Hopper('north');
        $hopper->add($chute = new Chute(10));
        $chute->inspector = $first = new Author('first');

        $this->em->persist($first);
        $this->em->persist($second = new Author('second'));
        $this->em->persist($hopper);
        $this->em->flush();

        $this->gateway->documents = [];

        $chute->inspector = $second;
        $chute->size = 20;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 10, 'new' => 20], $changes['chutes.'.$chute->id.'.size'] ?? null, 'the reading stopped at the association in front of it');
        self::assertArrayNotHasKey('chutes.'.$chute->id.'.inspector', $changes, 'an association of an element was recorded as a change inside it');
    }

    public function testEveryTrackedFieldOfAnElementIsRecordedAndNotJustTheFirst(): void
    {
        // Two fields of one element, both declared and both moved. Every other test here
        // arranges for one of them to be waved through, which is what they are for and
        // also what leaves the ordinary case — a line where two things changed at once —
        // without anything watching it.
        $depot = new Depot('north');
        $depot->add($case = new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $case->label = 'shelf-b';
        $case->weight = 25;
        $this->em->flush();

        $changes = $this->lastDocument()['changes'];

        self::assertSame(['old' => 'shelf-a', 'new' => 'shelf-b'], $changes['cases.'.$case->id.'.label'] ?? null);
        self::assertSame(['old' => 10, 'new' => 25], $changes['cases.'.$case->id.'.weight'] ?? null, 'the second field of the element was cut off the record');
    }

    public function testAnOwnerWithNothingOfItsOwnToSayIsStillRecordedForItsElement(): void
    {
        // A flush that gave the depot an UPDATE of its own and moved nothing audited on
        // it, while a line inside it changed. Left to its own change set the record is
        // empty and skipped — which is what skip_empty_updates is for — and the news
        // from inside the element would go with it.
        //
        // **Two things keep it, and only their pair is observable.** postUpdate asks
        // hasElementChanges() before skipping an empty record, and postFlush builds a
        // record for every owner that collected something, whether or not one is already
        // pending. Remove either and the record still arrives — measured, both ways
        // round, including the order it arrives in. Remove both and it is gone. So the
        // assertion here is about the outcome and not about either line, and the guard
        // in postUpdate is documented as an equivalent mutant for that reason.
        $depot = new Depot('north');
        $depot->add($case = new PackingCase('shelf-a', 10));

        $this->em->persist($depot);
        $this->em->flush();

        $this->gateway->documents = [];

        $depot->note = 'touched, and not audited';
        $case->weight = 25;
        $this->em->flush();

        $documents = $this->documents();

        self::assertCount(1, $documents, 'the record about the element went with the empty one for its owner');
        self::assertSame('depot', $documents[0]['objectType']);
        self::assertSame(['old' => 10, 'new' => 25], $documents[0]['changes']['cases.'.$case->id.'.weight'] ?? null);
    }
}
