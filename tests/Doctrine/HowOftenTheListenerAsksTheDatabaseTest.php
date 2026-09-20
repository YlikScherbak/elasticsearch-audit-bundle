<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Depot;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\PackingCase;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Route;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Stop;

/**
 * What auditing costs the database.
 *
 * A listener on every flush is a listener on every request, and the cost it adds is
 * paid by an application that asked for a history and not for a slower one. Two facts
 * about that cost are worth holding still, and neither is visible in a document: they
 * are questions asked or not asked, which is why they are counted here rather than
 * asserted about a record.
 *
 * The listener asks the database exactly one question of its own, and only in one
 * situation. Emptying an audited collection is the operation Doctrine reports by saying
 * nothing — it issues a DELETE and leaves no change set — so the old membership has to
 * be read back, or the history cannot say what was in it. That question is worth one
 * SELECT, and it must not be asked on behalf of a collection nobody audits.
 *
 * Everything else is free. Auditing what changed inside ten thousand lines of an order
 * reads what the unit of work already holds, so the cost is the application's own
 * updates and nothing on top — which is the shape a listener loses first, because one
 * lazy association read per element looks like nothing until the import runs.
 */
final class HowOftenTheListenerAsksTheDatabaseTest extends DoctrineTestCase
{
    public function testTheOldMembershipIsReadBackOnlyForACollectionSomebodyAudits(): void
    {
        $route = new Route('R-1');
        $route->stops->add($first = new Stop('a'));
        $route->detours->add($second = new Stop('b'));

        foreach ([$first, $second] as $stop) {
            $this->em->persist($stop);
        }

        $this->em->persist($route);
        $this->em->flush();

        $this->queries = [];
        $route->detours->clear();
        $this->em->flush();

        self::assertSame([], self::selects($this->queries), 'a collection nobody audits is emptied without a question');

        $this->queries = [];
        $route->stops->clear();
        $this->em->flush();

        $read = self::selects($this->queries);

        self::assertCount(1, $read, 'one question, asked once');
        self::assertStringContainsString('route_stop', $read[0], 'and it is the one that reads the membership back');
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
