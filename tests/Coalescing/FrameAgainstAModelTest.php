<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The buffer against a second implementation of the same rules.
 *
 * Every other test here names a case somebody thought of. This one does not: it makes
 * up sequences and asks whether two implementations agree about them, which is the
 * only way to hear about the case nobody thought of — the third actor arriving between
 * two saves of a record whose field had already gone somewhere and come back.
 *
 * **The model is written from the rules, not from the buffer.** That is the whole
 * discipline of the thing, and the reason this test was left until last: a reference
 * implementation derived by reading `FrameBuffer` would encode the same
 * misunderstanding twice and agree with itself forever. The rules it is written from
 * are the ones stated in the README and pinned by the tests next door:
 *
 *   1. Records are held by object, and one object's saves merge into one record.
 *   2. A record by a different actor lets the held one go and starts a new one —
 *      history must never say one person made a change another person made.
 *   3. A deletion lets the held record go first and then passes through: the last
 *      thing known about an object comes before the news that it is gone.
 *   4. Merging keeps the first old side and the last new side.
 *   5. A field that moved and came back is noise and is dropped; a field that never
 *      moved is kept as context; a record left saying nothing is not written at all.
 *   6. Closing the frame lets go of everything still held, in the order the objects
 *      were first touched in this frame — which is not the order the held records
 *      themselves started in, and the two differ as soon as another actor takes an
 *      object over. The distinction is observable: records written in one frame can
 *      share a timestamp, and a reader then orders them by the id given at write time,
 *      so the order they are let go in is the order somebody reads them in.
 *
 * Rule 6 is the one this test had to settle. The model and the buffer were written
 * from the same sentence — "in the order it arrived" — and read it differently, while
 * agreeing about the content of every record in every sequence. The wording above is
 * the buffer's reading, adopted deliberately rather than by default: it keeps the order
 * the operation touched things, and changing it would reorder history that applications
 * already have for no benefit.
 *
 * A failure here is not "the buffer is wrong" on its own — it is "these two disagree",
 * and the first question is which of them is reading the rules correctly.
 */
final class FrameAgainstAModelTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        // Fixed seeds rather than random ones: a failure has to be replayable by the
        // person reading it, and "it failed once on CI" is not a bug report. Widening
        // the search means adding seeds here, in a commit, deliberately.
        foreach (range(1, 40) as $seed) {
            yield 'seed '.$seed => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testTheBufferAndTheModelAgree(int $seed): void
    {
        $records = self::sequence($seed);

        $buffer = new FrameBuffer();
        $buffer->open();

        $fromBuffer = [];

        foreach ($records as $record) {
            foreach ($buffer->hold($record) as $released) {
                $fromBuffer[] = $released;
            }
        }

        foreach ($buffer->close() ?? [] as $released) {
            $fromBuffer[] = $released;
        }

        self::assertSame(
            self::describe(self::model($records)),
            self::describe($fromBuffer),
            sprintf("the buffer and the model disagree about this sequence (seed %d):\n%s", $seed, self::describe($records)),
        );
    }

    /**
     * The model: the rules in the class docblock, written as plainly as they can be.
     *
     * @param list<AuditRecord> $records
     *
     * @return list<AuditRecord>
     */
    private static function model(array $records): array
    {
        /** @var array<string, array{AuditRecord, array<string, Change>, array<string, true>}> $held */
        $held = [];
        $out = [];

        foreach ($records as $record) {
            $key = $record->objectType.'#'.$record->objectId;

            if ($record->event === AuditEvent::REMOVE) {
                // Rule 3.
                if (isset($held[$key])) {
                    $finalized = self::finalize(...$held[$key]);
                    unset($held[$key]);

                    if ($finalized !== null) {
                        $out[] = $finalized;
                    }
                }

                $out[] = $record;

                continue;
            }

            if (isset($held[$key]) && $held[$key][0]->actor !== $record->actor) {
                // Rule 2. Replaced in place rather than removed and put back: rule 6 says
                // the object keeps the position it first took, and unsetting the key would
                // move it to the end of the queue.
                $finalized = self::finalize(...$held[$key]);
                $held[$key] = [$record, [], []];

                if ($finalized !== null) {
                    $out[] = $finalized;
                }
            }

            if (!isset($held[$key])) {
                $held[$key] = [$record, [], []];
            }

            // Rules 1 and 4: the first old side, the last new side, and a note of every
            // field that ever differed.
            [$first, $sides, $moved] = $held[$key];

            foreach ($record->changes as $field => $change) {
                self::assertIsChange($change);

                $sides[$field] = new Change($sides[$field]->old ?? $change->old, $change->new);

                if ($change->old !== $change->new) {
                    $moved[$field] = true;
                }
            }

            $held[$key] = [$first, $sides, $moved];
        }

        // Rule 6.
        foreach ($held as [$first, $sides, $moved]) {
            $finalized = self::finalize($first, $sides, $moved);

            if ($finalized !== null) {
                $out[] = $finalized;
            }
        }

        return $out;
    }

    /**
     * Rule 5.
     *
     * @param array<string, Change> $sides
     * @param array<string, true>   $moved
     */
    private static function finalize(AuditRecord $first, array $sides, array $moved): ?AuditRecord
    {
        $changes = [];
        $anythingChanged = false;

        foreach ($sides as $field => $change) {
            if ($change->old !== $change->new) {
                $changes[$field] = $change;
                $anythingChanged = true;

                continue;
            }

            if (isset($moved[$field])) {
                continue; // went somewhere and came back
            }

            $changes[$field] = $change; // never moved: context
        }

        return $anythingChanged ? new AuditRecord(
            $first->objectType,
            $first->objectId,
            $first->event,
            actor: $first->actor,
            changes: $changes,
        ) : null;
    }

    /**
     * @return list<AuditRecord>
     */
    private static function sequence(int $seed): array
    {
        mt_srand($seed);

        $values = ['a', 'b', 'c'];
        $records = [];
        $current = [];

        foreach (range(1, mt_rand(2, 12)) as $ignored) {
            $id = mt_rand(1, 3);
            $actor = ['alice', 'bob', 'carol'][mt_rand(0, 2)];
            $key = 'order#'.$id;

            if (mt_rand(1, 10) === 1) {
                unset($current[$key]);
                $records[] = new AuditRecord('order', $id, AuditEvent::REMOVE, actor: $actor);

                continue;
            }

            $changes = [];

            foreach (['status', 'total'] as $field) {
                if (mt_rand(0, 1) === 0) {
                    continue; // not every save touches every field
                }

                $old = $current[$key][$field] ?? 'a';
                $new = $values[mt_rand(0, 2)];
                $current[$key][$field] = $new;
                $changes[$field] = new Change($old, $new);
            }

            if ($changes === []) {
                continue;
            }

            $records[] = new AuditRecord('order', $id, AuditEvent::UPDATE, actor: $actor, changes: $changes);
        }

        return $records;
    }

    /**
     * Both sides in one readable shape, so a disagreement reads as a diff rather than
     * as two object graphs.
     *
     * @param list<AuditRecord> $records
     */
    private static function describe(array $records): string
    {
        $lines = [];

        foreach ($records as $record) {
            $changes = [];

            foreach ($record->changes as $field => $change) {
                self::assertIsChange($change);

                $changes[] = sprintf('%s: %s -> %s', $field, var_export($change->old, true), var_export($change->new, true));
            }

            $lines[] = sprintf('%s#%s %s by %s [%s]', $record->objectType, $record->objectId, $record->event, $record->actor ?? '?', implode(', ', $changes));
        }

        return implode("\n", $lines)."\n";
    }

    private static function assertIsChange(mixed $change): void
    {
        self::assertInstanceOf(Change::class, $change, 'the generator only makes pairs');
    }
}
