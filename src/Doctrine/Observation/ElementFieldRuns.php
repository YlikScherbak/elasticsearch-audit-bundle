<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\ChangeSetBuilder;
use Borsche\ElasticsearchAuditBundle\Doctrine\ElementKey;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * What changed inside the elements of tracked collections, turned from the facts of the
 * connection's log into what the listener's records say: the owner, the flush, and the
 * changes keyed as the history names them.
 *
 * @internal the listener's reader of the log, kept apart from it
 */
final class ElementFieldRuns
{
    public function __construct(
        private readonly AuditMetadataFactory $audited,
        private readonly ValueComparatorInterface $comparator,
        private readonly LoggerInterface $logger,
        private readonly RowIdentity $identity,
    ) {
    }

    /**
     * What changed inside the elements of tracked collections, as the connection ran it:
     * a run per owner and flush, in the order the statements ran.
     *
     * A run is what one record says. The same key written a second time starts another --
     * two UPDATEs of one column are two facts, and a record holds a field once -- and so
     * does the same owner in a different flush, because a flush is what a record's moment,
     * actor and context belong to. Without savepoints a nested flush's statements are its
     * enclosing flush's (doctrine.nested_flush_provenance: outer), and they are that
     * flush's runs.
     *
     * A statement the log binds to a watched row and that no flush owns ran outside every
     * flush this listener saw: the application's own SQL written in the persister's shape
     * ($connection->update() is exactly that), or a hole in how flushes claim what they ran.
     * Neither is a flush's history, and neither is a failure of the flush that publishes --
     * so it is said in the log, by name, rather than dropped in silence or raised against
     * somebody else's operation.
     *
     * Only what came after $readThrough: the listener moves that once, where a flush's state is
     * forgotten, which follows every publishing whichever way it ended, and every dropping -- a
     * flush that wrote nothing of its own moves it past the ones before it too.
     *
     * @param bool $consume whether this is the reading that writes them: it looks the owner
     *                      up by reference when the identity map does not hold it, and says
     *                      what it cannot write. Counting, for a warning, does neither.
     *
     * @param (\Closure(int): bool)|null $duringAFlush whether the statement at a position ran while a flush of the listener's did
     *
     * @return list<array{owner: object|null, flush: int, changes: array<string, Change>}>
     */
    public function of(EntityManagerInterface $em, HistoryReplay $replay, int $readThrough, bool $consume = false, ?\Closure $duringAFlush = null): array
    {
        $builder = new ChangeSetBuilder($em, $this->comparator);
        $runs = [];
        $changes = [];
        $last = [];

        foreach ($replay->facts() as $fact) {
            $element = $fact['element'];

            if ($element === null || $fact['at'] <= $readThrough) {
                continue;
            }

            if ($fact['flush'] === null) {
                // Named by class, field and the columns of the key, and nothing a statement
                // carried: a value would be one more road out for what redaction keeps in.
                $what = $element['field'] ?? 'its place in '.$element['collection'];

                if ($consume && $duringAFlush !== null && $duringAFlush($fact['at'])) {
                    $this->logger->warning('A statement changed {field} of a {class} row, keyed by {key}, while a flush was running, and no flush claimed it, so it is not in the history. That is a hole in how the audit listener marks what a flush runs, and worth reporting.', [
                        'field' => $what,
                        'class' => $element['class'],
                        'key' => implode(', ', array_keys($element['key'])),
                    ]);
                } elseif ($consume) {
                    $this->logger->warning('A statement changed {field} of a {class} row, keyed by {key}, outside every flush, so it is not in the history: SQL the application ran itself, which the bundle does not audit.', [
                        'field' => $what,
                        'class' => $element['class'],
                        'key' => implode(', ', array_keys($element['key'])),
                    ]);
                }

                continue;
            }

            // The representer threw: the fact is left out rather than written with a value
            // nobody computed, and the failure goes through the policy -- once, by the caller,
            // at the position it happened, which is read past with everything else.
            if ($fact['failed']) {
                continue;
            }

            // An owner the same flush removed has its remove; the lines going with it are not
            // a second event, and neither is anything else its collection went through there.
            if ($replay->removedIn($em->getClassMetadata($element['owner'])->rootEntityName, \is_scalar($element['ownerKey']) ? (string) $element['ownerKey'] : '', $fact['flush'])) {
                continue;
            }

            $owner = $this->identity->byForeignKey($em, $element['owner'], $element['ownerKey'], $consume);
            $declaration = $this->audited->for($owner ?? $em->getClassMetadata($element['owner'])->newInstance());

            if ($declaration === null) {
                continue;
            }

            if ($element['kind'] === HistoryReplay::EMPTIED) {
                // The collection as it was, and nothing: what the emptying took, as the rows had it.
                $name = $element['collection'];
                $value = new Change($fact['old'], $fact['new']);
            } else {
                $elementId = $this->identity->historyId($em, $element['class'], $element['key']);

                if ($elementId === null) {
                    continue;
                }

                if ($element['kind'] === HistoryReplay::MEMBER || $element['field'] === null) {
                    // Every audited collection says what it gained and lost; only what changed
                    // INSIDE an element needs trackElements.
                    $name = ElementKey::of($element['collection'], $elementId);
                    $value = new Change($fact['old'], $fact['new']);
                } else {
                    $wanted = $declaration->trackedElementFields($element['collection']);
                    $change = $wanted === null ? null : $builder->elementFieldChange($declaration->objectType, $element['collection'], $elementId, $element['field'], $fact['old'], $fact['new'], $wanted);

                    if ($change === null) {
                        continue;
                    }

                    [$name, $value] = $change;
                }
            }
            $who = $element['owner'].'|'.json_encode($element['ownerKey']);
            $at = $last[$who] ?? null;

            if ($at === null || $runs[$at]['flush'] !== $fact['flush'] || isset($changes[$at][$name])) {
                $runs[] = ['owner' => $owner, 'flush' => $fact['flush'], 'changes' => []];
                $at = $last[$who] = array_key_last($runs);
            }

            $changes[$at][$name] = $value;
        }

        foreach ($runs as $at => $run) {
            $runs[$at]['changes'] = $changes[$at] ?? [];
        }

        // What the log could not be followed through, said once for the reading and by class
        // alone: nothing a statement carried. The history may be missing what those did, and
        // an empty list of known divergences in the tests means nothing if doubt vanishes here.
        $doubts = $consume ? $replay->doubtsAfter($readThrough) : [];

        if ($doubts !== []) {
            $this->logger->warning('What the connection ran could not be followed for {count} statement(s) of {classes} since the history was last written, so the history may be missing what they did.', [
                'count' => \count($doubts),
                'classes' => implode(', ', array_unique(array_map(static fn (array $doubt): string => $doubt['class'] ?? 'an unknown table', $doubts))),
            ]);
        }

        return $runs;
    }
}
