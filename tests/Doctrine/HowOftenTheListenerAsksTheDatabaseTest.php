<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Crate;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\CrateItem;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Depot;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PackingCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop;

/**
 * What auditing costs the database.
 *
 * Counted from after the test's own preparation, always: the schema its fixtures need, the
 * rows it writes to start from, are the test's statements and not the listener's. A fixture
 * added for another test adds its tables to every setUp -- two more statements per test
 * when an audited JOINED pair came in -- and that is not a cost of auditing to be optimised
 * away; nothing here counts it.
 *
 * A listener on every flush is a listener on every request, and the cost it adds is
 * paid by an application that asked for a history and not for a slower one. Two facts
 * about that cost are worth holding still, and neither is visible in a document: they
 * are questions asked or not asked, which is why they are counted here rather than
 * asserted about a record.
 *
 * The listener asks the database questions of its own in two situations, and they are the
 * same question: what rows a statement is about to change hold, where the statement will not
 * say. Emptying an audited collection is the operation Doctrine reports by saying nothing —
 * it issues a DELETE and leaves no change set — so the membership has to be read back, or
 * the history cannot say what was in it. And an owning ManyToMany's join rows (5.3a): what an
 * owner's rows hold, the first time a flush is about to touch them, and which owners hold a
 * target about to go -- whose rows the database takes with it, writing nothing. Each is read in
 * batches, never a SELECT per row: the holders of up to five hundred targets of a collection in
 * one, so a flush removing a hundred asks once. None is asked on behalf of a collection nobody
 * audits.
 *
 * Everything else is free. Auditing what changed inside ten thousand lines of an order
 * reads what the unit of work already holds, so the cost is the application's own
 * updates and nothing on top — which is the shape a listener loses first, because one
 * lazy association read per element looks like nothing until the import runs.
 */
final class HowOftenTheListenerAsksTheDatabaseTest extends DoctrineTestCase
{
    public function testTheOldMembershipIsReadBackOnlyForACollectionSomebodyAuditsAndOnlyWhereNothingKnowsIt(): void
    {
        $route = new Route('R-1');
        $route->stops->add($first = new Stop('a'));
        $route->detours->add($second = new Stop('b'));

        foreach ([$first, $second] as $stop) {
            $this->em->persist($stop);
        }

        $this->em->persist($route);
        $this->em->flush();

        // What its join rows hold is known from the flush that wrote them: emptied now, the
        // route asks nothing (5.3). Until 5.3 this was one question, read in onFlush before the
        // DELETE; before that two, a COUNT and a read of Doctrine's snapshot -- which is the
        // membership as of the last time Doctrine synchronised it, and was the mistake.
        $this->queries = [];
        $route->stops->clear();
        $this->em->flush();

        self::assertSame([], self::selects($this->queries), 'what the flush that created it wrote is its account');

        // A route written past this process, which nothing here has an account of: its rows are
        // read once, in onFlush, before the DELETE -- and a collection nobody audits is emptied
        // without a question at all. The one other question is the stop's own row, which its
        // representer reads for the name it records, as it would read it for any record.
        $native = $this->em->getConnection()->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->exec("INSERT INTO Stop (id, name) VALUES (900301, 'far'), (900302, 'further')");
        $native->exec("INSERT INTO Route (id, code) VALUES (900301, 'R-far')");
        $native->exec('INSERT INTO route_stop (route_id, stop_id) VALUES (900301, 900301)');
        $native->exec('INSERT INTO route_detours (route_id, stop_id) VALUES (900301, 900302)');
        $far = $this->em->find(Route::class, 900301);
        self::assertInstanceOf(Route::class, $far);

        $far->detours->clear();
        $this->queries = [];
        $this->em->flush();

        self::assertSame([], self::selects($this->queries), 'a collection nobody audits is emptied without a question');

        $far->stops->clear();
        $this->queries = [];
        $this->em->flush();

        $read = self::selects($this->queries);
        self::assertCount(1, array_filter($read, static fn (string $sql): bool => str_contains($sql, 'route_stop')), 'its membership read back once');
        self::assertSame([], array_values(array_filter($read, static fn (string $sql): bool => !str_contains($sql, 'route_stop') && !str_contains($sql, 'FROM Stop'))), 'and nothing else but the stop the record names');
    }

    public function testRemovingManyLinesTheMakerWayAddsNoSelect(): void
    {
        // Maker's removeItem() nulls the back-reference and lets orphanRemoval delete the
        // row, which made the two readers of whose row is going contradict each other for
        // every line -- and a contradiction was what the row was asked about: a hundred
        // SELECTs, then one per class. Whose row each DELETE took is what the connection's
        // log says, from the rows the listener remembers, and the bundle asks nothing. What is
        // counted is exactly that: the SELECTs added to the flush. The hundred DELETEs are
        // the application's, with or without the bundle.
        $this->attachListener(FailurePolicy::Log);

        $this->em->persist($crate = new Crate('C-1'));
        $lines = [];

        for ($i = 0; $i < 100; ++$i) {
            $crate->add($lines[] = new CrateItem('SKU-'.$i));
        }

        $this->em->flush();
        $this->queries = [];

        foreach ($lines as $line) {
            $line->crate = null;
            $crate->items->removeElement($line);
        }

        $this->em->flush();

        // One, for all hundred: a line is a target of a catalogue's audited ManyToMany, and a
        // target going is taken out of every list it was in by the database, with no statement --
        // so which catalogues held these is read before they go (5.3a), in one question.
        $selects = self::selects($this->queries);
        self::assertCount(1, $selects, 'one SELECT added for a hundred lines, not one a line');
        self::assertStringContainsString('catalogue_item', $selects[0], 'the holders of the lines, read before they go');
        self::assertCount(100, array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'DELETE')), 'the premise: the DELETEs ran, one a line');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM CrateItem'), 'and every one of them went');
    }

    public function testRemovingALineWhoseReadersAgreeAsksNothing(): void
    {
        // The ordinary $em->remove(): nothing about the owner changed, the readers agree,
        // and nothing is asked. The batch must not turn every removal into a question.
        $this->attachListener(FailurePolicy::Log);

        $this->em->persist($crate = new Crate('C-1'));
        $crate->add($line = new CrateItem('SKU-1'));
        $this->em->flush();
        $this->queries = [];

        $this->em->remove($line);
        $this->em->flush();

        // Asked about by nobody as a removal. What is asked is the one thing the rows cannot say
        // afterwards: which catalogues held the line, whose lists lose it with it (5.3a).
        $selects = self::selects($this->queries);
        self::assertCount(1, $selects, 'a removal nobody contradicts is asked about by nobody');
        self::assertStringContainsString('FROM catalogue_item j WHERE EXISTS', $selects[0], 'only who held it');
    }

    public function testACollectionThatDidNotMoveIsNotLoadedToFindThatOut(): void
    {
        // A lazy collection stays lazy. Asking what an audited collection holds means
        // loading it, and loading it for every flush that touched the entity but not the
        // collection is one SELECT per audited to-many per update — the cost that looks
        // like nothing until the nightly job touches fifty thousand rows.
        //
        // What makes it askable at all is that a dirty collection has to be loaded: its
        // snapshot would otherwise be empty and the record would claim every element is
        // new. The guard says which of the two this is, and the question is what it costs
        // to answer when the answer is "it did not move".
        $route = new Route('R-1');
        $route->stops->add($stop = new Stop('a'));

        $this->em->persist($stop);
        $this->em->persist($route);
        $this->em->flush();

        // Out of the identity map and back in, so the collection is a lazy one rather
        // than the array this test just filled.
        $id = $route->id;
        $this->em->clear();

        $route = $this->em->find(Route::class, $id);

        self::assertNotNull($route);
        self::assertFalse($route->stops->isInitialized(), 'the premise: the collection has not been read');

        $this->queries = [];

        $route->code = 'R-2';
        $this->em->flush();

        self::assertSame([], self::selects($this->queries), 'the collection was read to discover that it had not changed');
        self::assertFalse($route->stops->isInitialized(), 'and it is still unread afterwards');
    }

    public function testAuditingWhatChangedInsideTheElementsCostsNoQueryPerElement(): void
    {
        // One update per changed row, which is the application's own work, and nothing
        // added to it. The numbers are compared at two sizes because a constant overhead
        // and a per-element one look identical at one.
        foreach ([1, 4] as $elements) {
            $depot = new Depot('depot-'.$elements);
            $cases = [];

            for ($i = 0; $i < $elements; ++$i) {
                $depot->add($cases[] = new PackingCase('case-'.$i, $i));
            }

            $this->em->persist($depot);
            $this->em->flush();

            $this->queries = [];

            foreach ($cases as $case) {
                $case->weight += 100;
            }

            $this->em->flush();

            self::assertCount(
                $elements,
                $this->queries,
                sprintf('%d elements changed cost %d statements: %s', $elements, \count($this->queries), implode(' | ', $this->queries)),
            );
        }
    }

    /**
     * @param list<string> $queries
     *
     * @return list<string>
     */
    private static function selects(array $queries): array
    {
        return array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with(strtoupper(ltrim($sql)), 'SELECT')));
    }
}
