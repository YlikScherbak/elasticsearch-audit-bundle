<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Coalescing;

use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Exception\FrameOverflowException;
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
 *      moved is kept as context; an *update* left saying nothing is not written at all.
 *      A create is, whatever its fields say: the object coming into existence is the
 *      change, and an object created with nothing but defaults still appeared.
 *   6. Closing the frame lets go of everything still held, in the order the objects
 *      were first touched in this frame — which is not the order the held records
 *      themselves started in, and the two differ as soon as another actor takes an
 *      object over. The distinction is observable: records written in one frame can
 *      share a timestamp, and a reader then orders them by the id given at write time,
 *      so the order they are let go in is the order somebody reads them in.
 *   7. `max_held` bounds how many objects a frame holds at once, not how long any one
 *      of them merges: an object already held goes on merging however long the
 *      operation runs. It is consulted when an object the frame is not yet holding is
 *      about to join, and if the frame is already holding that many, the valve opens
 *      first and writes what it has. The frame stays open, and the object that opened
 *      it starts the next batch.
 *   8. Frames nest, and only the outermost one lets anything go: an inner `close()`
 *      answers with nothing at all, because what the buffer holds belongs to every
 *      level at once.
 *   9. An atomic frame promises that nothing of the operation is written before it
 *      closes, so the three early exits above are not exits any more. A remove and an
 *      actor boundary are *staged* — finished, and kept until the outermost close,
 *      which writes the staged ones before what is still held, since they happened
 *      first. And the valve cannot open, so reaching it refuses the whole operation
 *      instead: nothing that frame collected is ever written, including what it had
 *      staged, and the records that follow are dropped as they arrive.
 *
 * Rule 7 is why every sequence here runs against a `max_held` of a few records rather
 * than the default ten thousand. A model checked against a valve that never opens says
 * nothing about the valve, and the valve is the part that runs in production on the one
 * operation nobody sized for — the import, the batch job, the cascade that touched
 * forty thousand rows.
 *
 * Rule 6 was the one the first round had to settle. The model and the buffer were
 * written from the same sentence — "in the order it arrived" — and read it differently,
 * while agreeing about the content of every record in every sequence. The wording above
 * is the buffer's reading, adopted deliberately rather than by default: it keeps the
 * order the operation touched things, and changing it would reorder history that
 * applications already have for no benefit.
 *
 * The second round widened the sequences — creates, a `max_held` of a few records, and
 * atomic and nested frames — and three of the rules above came back sharper for it:
 *
 *   - rule 5 said "a record left saying nothing is not written at all", which is only
 *     true of updates. A create is news whatever its fields say. The generator had never
 *     made a create, so the wording had never been asked the question.
 *   - rule 7 said the valve opens when `max_held` is "reached". It opens when an object
 *     the frame is *not already holding* arrives and the frame is already holding that
 *     many — which is a different moment, and a different quantity: it bounds objects
 *     held at once, not how long one of them merges. The README said "reached" in its
 *     prose and "more than max_held" in both of its diagrams; the prose now agrees with
 *     the diagrams and with the code.
 *   - rule 9 needed the second half nobody would write from the prose: staged records
 *     count toward the valve too. Without that, one object and two actors taking turns
 *     stay under the valve forever while staging a record on every turn.
 *
 * A failure here is not "the buffer is wrong" on its own — it is "these two disagree",
 * and the first question is which of them is reading the rules correctly.
 */
final class FrameAgainstAModelTest extends TestCase
{
    private const OPEN = 'open';
    private const CLOSE = 'close';

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        // Fixed seeds rather than random ones: a failure has to be replayable by the
        // person reading it, and "it failed once on CI" is not a bug report. Widening
        // the search means adding seeds here, in a commit, deliberately.
        //
        // AUDIT_MODEL_SEEDS raises the count for a search run on somebody's machine —
        // `AUDIT_MODEL_SEEDS=5000 vendor/bin/phpunit --filter FrameAgainstAModel`. CI
        // stays at forty, and a seed that turns something up is added to the range here,
        // in a commit, so it is replayable forever after.
        $count = (int) ($_SERVER['AUDIT_MODEL_SEEDS'] ?? 40);

        foreach (range(1, max(1, $count)) as $seed) {
            yield 'seed '.$seed => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testTheBufferAndTheModelAgree(int $seed): void
    {
        $steps = self::sequence($seed);
        $maxHeld = self::maxHeldFor($seed);
        $atomic = self::atomicFor($seed);

        $buffer = new FrameBuffer(maxHeld: $maxHeld);
        $buffer->open($atomic);

        $fromBuffer = [];

        foreach ($steps as $step) {
            if ($step === self::OPEN) {
                $buffer->open();

                continue;
            }

            if ($step === self::CLOSE) {
                // Rule 8: an inner close answers with null, and this is here to say so
                // rather than to collect anything.
                foreach ($buffer->close() ?? [] as $released) {
                    $fromBuffer[] = $released;
                }

                continue;
            }

            try {
                foreach ($buffer->hold($step) as $released) {
                    $fromBuffer[] = $released;
                }
            } catch (FrameOverflowException) {
                // Rule 9: the operation is refused. Nothing it collected is written, and
                // the records after it are dropped as they arrive — which is what the
                // buffer does on its own, so the loop simply carries on.
            }
        }

        foreach ($buffer->close() ?? [] as $released) {
            $fromBuffer[] = $released;
        }

        self::assertSame(
            self::describe(self::model($steps, $maxHeld, $atomic)),
            self::describe($fromBuffer),
            sprintf("the buffer and the model disagree about this sequence (seed %d, max_held %d, %s):\n%s", $seed, $maxHeld, $atomic ? 'atomic' : 'ordinary', self::describe($steps)),
        );
    }

    /**
     * Small enough that the valve opens somewhere in most sequences, and varied so that
     * it opens in different places in different ones.
     */
    private static function maxHeldFor(int $seed): int
    {
        return 2 + $seed % 4;
    }

    /**
     * Every third sequence is atomic, which is enough of them for the staging and the
     * refusal to meet every other rule somewhere in the forty.
     */
    private static function atomicFor(int $seed): bool
    {
        return $seed % 3 === 0;
    }

    /**
     * The model: the rules in the class docblock, written as plainly as they can be.
     *
     * @param list<AuditRecord|self::OPEN|self::CLOSE> $steps
     *
     * @return list<AuditRecord>
     */
    private static function model(array $steps, int $maxHeld, bool $atomic): array
    {
        /** @var array<string, array{AuditRecord, array<string, Change>, array<string, true>}> $held */
        $held = [];
        /** @var list<AuditRecord> $staged records an atomic frame finished with and keeps until it closes */
        $staged = [];
        $out = [];
        $depth = 1;
        $refused = false;

        // Rule 9: where a record goes when the frame has finished with it. In an
        // ordinary frame it is written; in an atomic one it waits — and waiting counts,
        // so staging is the second place the valve can be reached. Without that, one
        // object and two actors taking turns keep the frame under the valve forever
        // while it stages a record on every turn.
        $letGo = static function (?AuditRecord $record) use (&$out, &$staged, &$held, &$refused, $atomic, $maxHeld): void {
            if ($record === null) {
                return;
            }

            if (!$atomic) {
                $out[] = $record;

                return;
            }

            $staged[] = $record;

            if (\count($held) + \count($staged) > $maxHeld) {
                $held = [];
                $staged = [];
                $refused = true;
            }
        };

        foreach ($steps as $step) {
            // Rule 8: a level of its own, and nothing leaves until the last one goes.
            if ($step === self::OPEN) {
                ++$depth;

                continue;
            }

            if ($step === self::CLOSE) {
                --$depth;

                continue;
            }

            if ($refused) {
                continue; // rule 9: the operation has no history, including what comes after
            }

            $record = $step;
            $key = $record->objectType.'#'.$record->objectId;

            if ($record->event === AuditEvent::REMOVE) {
                // Rule 3.
                if (isset($held[$key])) {
                    $finalized = self::finalize(...$held[$key]);
                    unset($held[$key]);
                    $letGo($finalized);
                }

                $letGo($record);

                continue;
                // The refusal, if one happened, is read at the top of the next step.
            }

            if (isset($held[$key]) && $held[$key][0]->actor !== $record->actor) {
                // Rule 2. Replaced in place rather than removed and put back: rule 6 says
                // the object keeps the position it first took, and unsetting the key would
                // move it to the end of the queue.
                $finalized = self::finalize(...$held[$key]);
                $held[$key] = [$record, [], []];
                $letGo($finalized);

                if ($refused) {
                    continue; // the frame kept nothing, so there is nothing to merge into
                }
            }

            if (!isset($held[$key])) {
                // Rule 7: the valve is consulted here and nowhere else — when an object
                // the frame is not already holding is about to join. What the frame holds
                // already can go on merging however long the operation runs; what the
                // valve bounds is how many objects at once.
                //
                // Rule 9: an atomic frame counts everything it is keeping back, staged
                // records included, and cannot open the valve at all — so reaching it
                // refuses the operation and throws all of it away.
                if ($atomic && \count($held) + \count($staged) >= $maxHeld) {
                    $held = [];
                    $staged = [];
                    $refused = true;

                    continue;
                }

                if (!$atomic && \count($held) >= $maxHeld) {
                    foreach ($held as $heldEntry) {
                        $finalized = self::finalize(...$heldEntry);

                        if ($finalized !== null) {
                            $out[] = $finalized;
                        }
                    }

                    $held = [];
                }

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

        if ($refused) {
            return $out; // rule 9: an atomic frame that overflowed wrote nothing at all
        }

        // Rule 9: what was staged happened before what is still held, and goes first.
        foreach ($staged as $record) {
            $out[] = $record;
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

        // Rule 5: only an update has to have said something.
        return $anythingChanged || $first->event !== AuditEvent::UPDATE ? new AuditRecord(
            $first->objectType,
            $first->objectId,
            $first->event,
            actor: $first->actor,
            changes: $changes,
        ) : null;
    }

    /**
     * @return list<AuditRecord|self::OPEN|self::CLOSE>
     */
    private static function sequence(int $seed): array
    {
        mt_srand($seed);

        $values = ['a', 'b', 'c'];
        $records = [];
        $current = [];
        $seen = [];
        $inner = 0;

        foreach (range(1, mt_rand(2, 12)) as $ignored) {
            // Inner frames, always balanced: the outermost one belongs to the test, and
            // closing it early would end the sequence rather than nest inside it.
            if (mt_rand(1, 6) === 1) {
                $records[] = self::OPEN;
                ++$inner;
            } elseif ($inner > 0 && mt_rand(1, 4) === 1) {
                $records[] = self::CLOSE;
                --$inner;
            }

            $id = mt_rand(1, 3);
            $actor = ['alice', 'bob', 'carol'][mt_rand(0, 2)];
            $key = 'order#'.$id;

            if (mt_rand(1, 10) === 1) {
                unset($current[$key], $seen[$key]);
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

            // A create is the first thing said about an object, and what follows merges
            // into it: the event a merged record carries is the one it started with, so
            // "created, then edited twice" has to stay a create.
            $event = !isset($seen[$key]) && mt_rand(1, 4) === 1 ? AuditEvent::CREATE : AuditEvent::UPDATE;
            $seen[$key] = true;

            $records[] = new AuditRecord('order', $id, $event, actor: $actor, changes: $changes);
        }

        // range(1, 0) is [1, 0] in PHP, not the empty list: closing twice here would
        // close the frame the test owns and end the sequence instead of balancing it.
        while ($inner-- > 0) {
            $records[] = self::CLOSE;
        }

        return $records;
    }

    /**
     * Both sides in one readable shape, so a disagreement reads as a diff rather than
     * as two object graphs.
     *
     * @param list<AuditRecord|self::OPEN|self::CLOSE> $records
     */
    private static function describe(array $records): string
    {
        $lines = [];

        foreach ($records as $record) {
            if ($record === self::OPEN || $record === self::CLOSE) {
                $lines[] = '--- '.$record.' a frame';

                continue;
            }

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
