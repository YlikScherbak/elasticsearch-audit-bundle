<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Depot;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PackingCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;

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
}
