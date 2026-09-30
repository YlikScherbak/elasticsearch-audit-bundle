<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Exception\DeclarationMistake;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;

/**
 * An owning ManyToMany's facts put together as the history says them (5.3b): the whole list
 * before and after, `old [...] -> new [...]`, one move of the field for each contribution --
 * the form 1.x promises its readers, built from the join rows and not from the collection.
 *
 * **What one contribution is.** The facts of one owner's collection that ran one after another
 * in one flush: nothing of another flush's between them -- a flush begun in between ends it, as
 * a nested flush's work of its own is its own -- and no link met twice. A link added and taken
 * away again is two moves of the field, two records, whether or not a frame later folds them;
 * the list is never folded here into having said nothing.
 *
 * **What the lists are.** What the owner's rows held before the contribution's first fact and
 * after its last, as {@see LinkFacts} tells them -- so what the log took back is in neither,
 * the fate of each fact decided before anything is put together: a link added and rolled back,
 * then another added, is a move from a list without the first to one with the second.
 *
 * **In what order.** By the target's key, column by column in the order of its identifier --
 * as numbers where both are, as text otherwise -- and not by the order the identity map or the
 * collection held them in.
 *
 * **As what.** Each target by the collection's representer, as it stood at the moment of the
 * contribution -- the old list before its first statement, the new one once its last ran: for
 * a class the history watches, a copy of the row as the replay holds it there, pointing where
 * the row pointed (what it points at is the manager's: the row is shown as it stood, not all
 * it leads to); for another, whose rows are not read, the object the application removed, or
 * the entity the manager holds, or a reference to it -- named by its identifier when the
 * representer finds the row gone. Anything else a representer throws is the application's
 * failure, and the contribution is not written.
 *
 * The listener publishes these: the first contribution of an owner's collection in a flush
 * joins the owner's record of that flush, and each after it is a record of its own.
 */
final class LinkRuns
{
    public function __construct(
        private readonly RowIdentity $identity,
        private readonly AuditMetadataFactory $audited = new AuditMetadataFactory(),
    ) {
    }

    /**
     * @param (\Closure(\Throwable): void)|null $failed what is done with a failure building one contribution; null throws it
     *
     * @return list<array{association: string, owner: class-string, ownerId: string, ownerKey: array<string, mixed>, collection: string, flush: int|null, at: list<int>, old: list<mixed>, new: list<mixed>}>
     */
    public function of(EntityManagerInterface $em, HistoryReplay $replay, StatementLog $log, LinkFacts $told, ?DepartedObjects $departed = null, ?\Closure $failed = null): array
    {
        $byOwner = [];

        foreach ($told->facts() as $fact) {
            $byOwner[$fact['association']][$fact['ownerId']][] = $fact;
        }

        $starts = $told->starts();
        $runs = [];

        foreach ($byOwner as $of => $owners) {
            foreach ($owners as $id => $facts) {
                $marks = $starts[$of][(string) $id] ?? [];
                $next = 0;
                $state = null;
                $open = null;

                // By statement: what one statement did -- all of an owner's links taken, say -- is
                // one step of the list, never split between two contributions.
                $statements = [];

                foreach ($facts as $fact) {
                    $statements[$fact['at']][] = $fact;
                }

                foreach ($statements as $at => $ofTheStatement) {
                    $first = $ofTheStatement[0];

                    // What the rows held before it: the last point it was established at, and
                    // the facts since, which this has been carrying out.
                    $reset = false;

                    while (isset($marks[$next]) && $marks[$next]['at'] <= $at) {
                        $state = $marks[$next]['targets'];
                        ++$next;
                        $reset = true;
                    }

                    if ($state === null) {
                        continue; // not known there: LinkFacts has said so, and made no fact
                    }

                    $again = array_filter($ofTheStatement, static fn (array $fact): bool => isset($open['touched'][$fact['targetId']]));

                    if ($open === null
                        || $reset
                        || $first['flush'] !== $open['flush']
                        || $again !== []
                        || self::aFlushBeganBetween($log, $open['last'], $at)
                    ) {
                        if ($open !== null) {
                            $runs[] = $open;
                        }

                        $open = ['fact' => $first, 'flush' => $first['flush'], 'at' => [], 'last' => $at, 'old' => $state, 'new' => $state, 'touched' => []];
                    }

                    foreach ($ofTheStatement as $fact) {
                        if ($fact['arrived']) {
                            $state[$fact['targetId']] = $fact['targetKey'];
                        } else {
                            unset($state[$fact['targetId']]);
                        }

                        $open['touched'][$fact['targetId']] = true;
                    }

                    $open['new'] = $state;
                    $open['last'] = $at;
                    $open['at'][] = $at;
                }

                if ($open !== null) {
                    $runs[] = $open;
                }
            }
        }

        usort($runs, static fn (array $one, array $other): int => [$one['at'][0], $one['fact']['association'], $one['fact']['ownerId']] <=> [$other['at'][0], $other['fact']['association'], $other['fact']['ownerId']]);
        $said = [];

        foreach ($runs as $run) {
            $fact = $run['fact'];

            try {
                $said[] = [
                    'association' => $fact['association'],
                    'owner' => $fact['owner'],
                    'ownerId' => $fact['ownerId'],
                    'ownerKey' => $fact['ownerKey'],
                    'collection' => $fact['collection'],
                    'flush' => $run['flush'],
                    'at' => $run['at'],
                    'old' => $this->listOf($em, $replay, $departed, $fact['owner'], $fact['collection'], $fact['target'], $run['old'], $run['at'][0], false),
                    'new' => $this->listOf($em, $replay, $departed, $fact['owner'], $fact['collection'], $fact['target'], $run['new'], $run['at'][\count($run['at']) - 1], true),
                ];
            } catch (\Throwable $e) {
                if ($failed === null) {
                    throw $e;
                }

                $failed($e);
            }
        }

        return $said;
    }

    /** Whether a flush began after one statement and before another, a later one. */
    private static function aFlushBeganBetween(StatementLog $log, int $from, int $to): bool
    {
        for ($at = $from; $at < $to; ++$at) {
            if ($log->aFlushStartedAfter($at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The targets as the history names them, in the order of their keys.
     *
     * @param class-string                        $owner
     * @param class-string                        $target
     * @param array<string, array<string, mixed>> $targets by the target's id
     *
     * @return list<mixed>
     */
    private function listOf(EntityManagerInterface $em, HistoryReplay $replay, ?DepartedObjects $departed, string $owner, string $collection, string $target, array $targets, int $at, bool $once): array
    {
        $represent = $this->audited->forClass($em->getClassMetadata($owner)->name, $em->getClassMetadata($owner)->newInstance(...))?->fields[$collection] ?? null;

        if ($represent === null) {
            throw new DeclarationMistake(sprintf('An audited association needs a representer (a callable turning %s into what to store).', $target));
        }

        $columns = $em->getClassMetadata($target)->getIdentifierColumnNames();
        $keys = array_values($targets);
        usort($keys, static function (array $one, array $other) use ($columns): int {
            foreach ($columns as $column) {
                $a = $one[$column] ?? null;
                $b = $other[$column] ?? null;
                $order = is_numeric($a) && is_numeric($b) ? (float) $a <=> (float) $b : strcmp((string) $a, (string) $b);

                if ($order !== 0) {
                    return $order;
                }
            }

            return 0;
        });

        return array_map(function (array $key) use ($em, $replay, $departed, $target, $represent, $at, $once): mixed {
            $object = $replay->copyAt($target, $key, $at, $once)
                ?? $departed?->find($em, $target, $key)
                ?? $this->identity->managed($em, $target, $key);

            if ($object === null) {
                $values = $this->identity->identifierValues($em, $target, $key);

                if ($values === null) {
                    return null;
                }

                $object = $em->getReference($target, $values);

                if ($object === null) {
                    return $this->identity->historyId($em, $target, $key);
                }
            }

            try {
                return $represent($object);
            } catch (EntityNotFoundException) {
                // A reference to a row that is not there -- gone past anything this read.
                return $this->identity->historyId($em, $target, $key);
            }
        }, $keys);
    }
}
