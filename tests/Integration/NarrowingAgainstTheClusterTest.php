<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Integration;

use Borsche\ElasticsearchAuditBundle\Elasticsearch\IndexDefinition;
use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\QueryBuilder;
use Borsche\ElasticsearchAuditBundle\Tests\Reader\NarrowingChains;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The same question as NarrowingOnlyEverNarrowsTest, asked of Elasticsearch.
 *
 * That test proves the property against an evaluator written for it — a hundred lines
 * that read term, terms, ids, exists, range and bool. Which makes it a third
 * implementation of the semantics, and the property is only proven relative to it: if
 * the evaluator reads a range over dates, or a bool, differently from the cluster, the
 * test passes where Elasticsearch would not.
 *
 * So the same forty seeds run here against a live one. The corpus is indexed once, the
 * evaluator is replaced by _search, and the assertions are word for word the same. The
 * chains and the documents come from the class both tests share, because two generators
 * that had drifted apart would be two tests agreeing about nothing.
 *
 * What the pair gives is what the frame's model test gives: the fast one runs
 * everywhere and catches the mistake, and the slow one says the fast one is asking the
 * right question.
 *
 * **If the two ever disagree, the evaluator is what changes.** Not the corpus, and not
 * the chains. A disagreement here is news about the evaluator reading the DSL
 * differently from the cluster — a range over dates, a bool, a keyword against a number
 * — and the cluster is the thing the bundle actually talks to. Narrowing the corpus
 * until both agree would make the pair agree about less, which is the opposite of why
 * there are two of them; the reading has to be fixed, and the document that caught it
 * kept.
 */
#[Group('integration')]
final class NarrowingAgainstTheClusterTest extends ElasticsearchTestCase
{
    private static ?string $index = null;

    /**
     * Indexed once for the whole class rather than per test: the corpus is the same
     * thirty-six documents for every seed, and forty rounds of creating an index and
     * filling it is four hundred requests spent saying the same thing.
     */
    private function corpusIndex(): string
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = 'audit_test_narrowing_'.bin2hex(random_bytes(4));

        self::client()->indices()->create([
            'index' => $index,
            'body' => (new IndexDefinition())->withProperties(['channel' => ['type' => 'keyword']])->toArray(),
        ]);

        foreach (NarrowingChains::corpus() as $document) {
            self::client()->index([
                'index' => $index,
                'id' => $document['_id'],
                'body' => $document['_source'],
            ]);
        }

        self::client()->indices()->refresh(['index' => $index]);

        return self::$index = $index;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$index !== null) {
            try {
                self::client()->indices()->delete(['index' => self::$index]);
            } catch (\Throwable) {
                // A cluster that has already lost it is not this test's problem.
            }

            self::$index = null;
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        return NarrowingChains::seeds();
    }

    #[DataProvider('seeds')]
    public function testANarrowingStepNeverReachesMoreThanTheStepBeforeIt(int $seed): void
    {
        $query = AuditQuery::for('order');
        $reachedBefore = $this->reach($query);

        foreach (NarrowingChains::steps($seed) as $step) {
            $before = $query;

            try {
                $query = ($step['apply'])($query);
            } catch (InvalidQueryException) {
                continue; // a combination the API refuses; refusals are tested where they are made
            }

            $reached = $this->reach($query);

            if ($step['narrows']) {
                self::assertSame(
                    [],
                    array_values(array_diff($reached, $reachedBefore)),
                    sprintf(
                        "%s reached documents the query before it did not, on the cluster (seed %d):\nbefore: %s\nafter:  %s",
                        $step['name'],
                        $seed,
                        implode(', ', $reachedBefore),
                        implode(', ', $reached),
                    ),
                );
            } else {
                self::assertSame(
                    $reached,
                    $this->reach(($step['apply'])(($step['applyOther'])($before))),
                    sprintf('%s did not replace what was already there for "%s", on the cluster (seed %d)', $step['name'], $step['field'], $seed),
                );
            }

            $reachedBefore = $reached;
        }
    }

    /**
     * The ids the cluster answers with. Only the query part of the body: what is under
     * test is which documents match, and paging is somebody else's promise.
     *
     * @return list<string>
     */
    private function reach(AuditQuery $query): array
    {
        $response = self::client()->search([
            'index' => $this->corpusIndex(),
            'body' => ['query' => (new QueryBuilder())->build($query)['query'], 'size' => 200],
        ])->asArray();

        $reached = [];

        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $reached[] = (string) $hit['_id'];
        }

        // Sorted, because what is under test is which documents match and not the order
        // they come back in: every clause this builds is a filter, filters do not score,
        // and a search with nothing to sort by answers in whatever order it likes. Ids
        // are unique, so a sorted list is the set.
        sort($reached);

        return $reached;
    }
}
