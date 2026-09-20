<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Reader;

use Borsche\ElasticsearchAuditBundle\Model\AuditQuery;

/**
 * The chains and the corpus the narrowing tests walk, in one place because two tests
 * walk them: one evaluating the query itself, one asking a live cluster.
 *
 * Shared rather than copied on purpose. The whole value of the second test is that it
 * answers the same question about the same sequences with a different oracle, and two
 * generators that had drifted apart would be two tests agreeing about nothing.
 *
 * The steps for a seed are generated up front rather than as the chain is walked, so
 * the sequence depends on the seed alone and not on what a test does between them.
 */
final class NarrowingChains
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        // Fixed, for the reason the frame's model test gives: a failure has to be
        // replayable, and "it failed once on CI" is not a bug report. The env variable
        // widens a search run on somebody's machine; CI stays at forty.
        $count = (int) ($_SERVER['AUDIT_MODEL_SEEDS'] ?? 40);

        foreach (range(1, max(1, $count)) as $seed) {
            yield 'seed '.$seed => [$seed];
        }
    }

    /**
     * @return list<array{name: string, field: string, narrows: bool, apply: \Closure(AuditQuery): AuditQuery, applyOther: \Closure(AuditQuery): AuditQuery}>
     */
    public static function steps(int $seed): array
    {
        mt_srand($seed);

        $steps = [];

        foreach (range(1, mt_rand(2, 8)) as $ignored) {
            $steps[] = self::aStep();
        }

        return $steps;
    }

    /**
     * Every document both tests ask about: four object ids, three actors, three events,
     * and a channel that is absent from every fourth record — which is what exists() and
     * missing() are about.
     *
     * @return list<array{_id: string, _source: array<string, mixed>}>
     */
    public static function corpus(): array
    {
        $documents = [];
        $n = 0;

        foreach ([1, 2, 3, 4] as $objectId) {
            foreach (['ada', 'bob', 'cleo'] as $actor) {
                foreach (['create', 'update', 'remove'] as $event) {
                    $channel = [null, 'web', 'app', 'till'][$n % 4];

                    $source = [
                        'objectType' => 'order',
                        'objectId' => $objectId,
                        'event' => $event,
                        'source' => $actor,
                        'loggedAt' => sprintf('2026-08-%02d 10:00:00', ($n % 27) + 1),
                    ];

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
}
