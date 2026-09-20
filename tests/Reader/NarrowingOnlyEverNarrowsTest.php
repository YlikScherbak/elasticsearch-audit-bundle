<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Exception\InvalidQueryException;
use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;
use Borsche\ElasticsearchAuditBundle\Reader\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one promise of this API that is a security property.
 *
 * `narrow*()` exists so that an application can hand a query to something it does not
 * trust and get back a query that cannot see more than the one it gave. A visibility
 * extension narrows to the actors a viewer may read; an endpoint narrows to the object
 * the URL names. If a narrow could ever widen — by replacing a list instead of
 * intersecting it, by dropping a condition it did not understand — the result is one
 * viewer reading another's history, and nothing about the answer looks wrong.
 *
 * Mutation testing cannot reach this. It changes what a method does, one expression at
 * a time, and the mistake here is a method doing the *other method's* job — which is
 * not a mutation of anything, and would leave every existing test green because each
 * one asks about one call.
 *
 * So this asks the question the property is really about: not what the query object
 * holds, but which documents the cluster would answer with. Random chains of calls are
 * built, the Elasticsearch body is produced for each step, and the body is evaluated
 * against a fixed corpus by a small reader of the clause vocabulary the builder uses.
 * After every narrowing step the set of matched documents must be a subset of the set
 * before it. After every replacing step the same call made twice with different values
 * must reach exactly what the second one alone reaches — which is what "replaces rather
 * than merges" means, asked where the call happens rather than by rebuilding the chain
 * without it. Rebuilding would be a different query: a narrowing step over a field
 * nobody has conditioned yet *sets* it, so taking away what came before changes what
 * came after.
 *
 * Fixed seeds, for the reason the frame's model test gives: a failure has to be
 * replayable, and "it failed once on CI" is not a bug report.
 */
final class NarrowingOnlyEverNarrowsTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        $count = (int) ($_SERVER['AUDIT_MODEL_SEEDS'] ?? 40);

        foreach (range(1, max(1, $count)) as $seed) {
            yield 'seed '.$seed => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testANarrowingStepNeverReachesMoreThanTheStepBeforeIt(int $seed): void
    {
        mt_srand($seed);

        $documents = self::corpus();
        $query = AuditQuery::for('order');

        foreach (range(1, mt_rand(2, 8)) as $ignored) {
            $step = self::aStep();
            $before = $query;

            try {
                $query = ($step['apply'])($query);
            } catch (InvalidQueryException) {
                // A combination the API refuses outright — narrowing a range by a list,
                // say. Refusals are a promise of their own and are tested where they are
                // made; here the step simply did not happen.
                continue;
            }

            $reached = self::reach($query, $documents);
            $reachedBefore = self::reach($before, $documents);

            if ($step['narrows']) {
                self::assertSame(
                    [],
                    array_values(array_diff($reached, $reachedBefore)),
                    sprintf(
                        "%s reached documents the query before it did not (seed %d):\nbefore: %s\nafter:  %s",
                        $step['name'],
                        $seed,
                        implode(', ', $reachedBefore),
                        implode(', ', $reached),
                    ),
                );

                continue;
            }

            // A replacing step, checked where it happens rather than by rebuilding the
            // chain without it: rebuilding is not the same query, because a narrowing
            // step over a field nobody has conditioned yet *sets* it instead of
            // intersecting, so removing what came before changes what came after.
            //
            // Applied twice with different values, the second has to be the whole of
            // what the field says. That is the difference between replacing and merging,
            // and it is local.
            self::assertSame(
                $reached,
                self::reach(($step['apply'])(($step['applyOther'])($before)), $documents),
                sprintf('%s did not replace what was already there for "%s" (seed %d)', $step['name'], $step['field'], $seed),
            );
        }
    }

    /**
     * One call, with the values it carries — and, for a replacing one, the same call
     * with different values, which is what "replaces" is asked against.
     *
     * @return array{name: string, field: string, narrows: bool, apply: \Closure(AuditQuery): AuditQuery, applyOther: \Closure(AuditQuery): AuditQuery}
     */
    private static function aStep(): array
    {
        $ids = [1, 2, 3, 4];
        $actors = ['ada', 'bob', 'cleo'];
        $events = ['create', 'update', 'remove'];
        $channels = ['web', 'app', 'till'];

        $some = static fn (array $pool): array => array_values(array_intersect_key($pool, array_flip((array) array_rand($pool, mt_rand(1, \count($pool))))));

        $steps = [
            ['withObjectIds', 'objectId', false, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->withObjectIds(...$v), $ids],
            ['narrowObjectIds', 'objectId', true, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->narrowObjectIds(...$v), $ids],
            ['withActors', 'actor', false, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->withActors(...$v), $actors],
            ['narrowActors', 'actor', true, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->narrowActors(...$v), $actors],
            ['withEvents', 'event', false, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->withEvents(...$v), $events],
            ['whereIn', 'channel', false, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->whereIn('channel', $v), $channels],
            ['narrowIn', 'channel', true, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->narrowIn('channel', $v), $channels],
            ['where', 'channel', false, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->where('channel', $v[0]), $channels],
            ['whereExists', 'channel', false, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->whereExists('channel'), $channels],
            ['whereNotExists', 'channel', false, static fn (array $v): \Closure => static fn (AuditQuery $q): AuditQuery => $q->whereNotExists('channel'), $channels],
        ];

        [$name, $field, $narrows, $make, $pool] = $steps[mt_rand(0, \count($steps) - 1)];

        return [
            'name' => $name,
            'field' => $field,
            'narrows' => $narrows,
            'apply' => $make($some($pool)),
            'applyOther' => $make($some($pool)),
        ];
    }

    /**
     * The ids of the documents this query would come back with.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return list<string>
     */
    private static function reach(AuditQuery $query, array $documents): array
    {
        $body = (new QueryBuilder())->build($query)['query'];
        $reached = [];

        foreach ($documents as $document) {
            if (self::clauseMatches($body, $document)) {
                $reached[] = (string) $document['_id'];
            }
        }

        sort($reached);

        return $reached;
    }

    /**
     * A reader for the clauses this builder produces, and only those: a clause it has
     * never seen is a failure rather than a "no", so the day the builder learns a new
     * one this test says so instead of quietly answering about the old vocabulary.
     *
     * @param array<string, mixed> $clause
     * @param array<string, mixed> $document
     */
    private static function clauseMatches(array $clause, array $document): bool
    {
        $source = $document['_source'];
        self::assertIsArray($source);

        if (isset($clause['match_all'])) {
            return true;
        }

        if (isset($clause['match_none'])) {
            return false;
        }

        if (isset($clause['term'])) {
            self::assertIsArray($clause['term']);
            $field = array_key_first($clause['term']);

            return \array_key_exists((string) $field, $source) && $source[$field] === $clause['term'][$field];
        }

        if (isset($clause['terms'])) {
            self::assertIsArray($clause['terms']);
            $field = array_key_first($clause['terms']);
            $values = $clause['terms'][$field];
            self::assertIsArray($values);

            return \array_key_exists((string) $field, $source) && \in_array($source[$field], $values, true);
        }

        if (isset($clause['ids'])) {
            self::assertIsArray($clause['ids']);
            $values = $clause['ids']['values'];
            self::assertIsArray($values);

            return \in_array($document['_id'], $values, true);
        }

        if (isset($clause['exists'])) {
            self::assertIsArray($clause['exists']);

            return \array_key_exists((string) $clause['exists']['field'], $source);
        }

        if (isset($clause['range'])) {
            self::assertIsArray($clause['range']);
            $field = array_key_first($clause['range']);
            $bounds = $clause['range'][$field];
            self::assertIsArray($bounds);

            if (!\array_key_exists((string) $field, $source)) {
                return false;
            }

            $value = $source[$field];

            return (!isset($bounds['gte']) || $value >= $bounds['gte']) && (!isset($bounds['lte']) || $value <= $bounds['lte']);
        }

        if (isset($clause['bool'])) {
            self::assertIsArray($clause['bool']);

            foreach ($clause['bool']['filter'] ?? [] as $inner) {
                self::assertIsArray($inner);

                if (!self::clauseMatches($inner, $document)) {
                    return false;
                }
            }

            foreach ($clause['bool']['must_not'] ?? [] as $inner) {
                self::assertIsArray($inner);

                if (self::clauseMatches($inner, $document)) {
                    return false;
                }
            }

            return true;
        }

        self::fail('the builder produced a clause this test cannot read: '.json_encode($clause));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function corpus(): array
    {
        $documents = [];
        $n = 0;

        foreach ([1, 2, 3, 4] as $objectId) {
            foreach (['ada', 'bob', 'cleo'] as $actor) {
                foreach (['create', 'update', 'remove'] as $event) {
                    // Every fourth record has no channel at all, which is what exists()
                    // and missing() are about.
                    $channel = [null, 'web', 'app', 'till'][$n % 4];

                    $source = ['objectType' => 'order', 'objectId' => $objectId, 'event' => $event, 'source' => $actor, 'loggedAt' => sprintf('2026-08-%02d 10:00:00', ($n % 27) + 1)];

                    if ($channel !== null) {
                        $source['channel'] = $channel;
                    }

                    $documents[] = ['_id' => 'd'.$n, '_source' => $source];
                    ++$n;
                }
            }
        }

        return $documents;
    }
}
