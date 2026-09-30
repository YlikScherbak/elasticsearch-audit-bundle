<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\ChangeSetBuilder;
use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadata;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What each execution did to the row of an audited entity, turned from the facts of the
 * connection's log into what the listener's records say: the event, the entity, the flush,
 * the statements it was, and the changes keyed as the history names them.
 *
 * The listener's records of an entity's own row are these ({@see \Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber}).
 *
 * What an owning collection went through is not here: its join rows are no fact of the
 * entity's row, and are told by {@see LinkFacts} and put together by {@see LinkRuns}.
 *
 * @internal the listener's reader of the log, kept apart from it
 */
final class EntityRowRuns
{
    private const EVENTS = [
        StatementShape::INSERT => AuditEvent::CREATE,
        StatementShape::UPDATE => AuditEvent::UPDATE,
        StatementShape::DELETE => AuditEvent::REMOVE,
    ];

    public function __construct(
        private readonly AuditMetadataFactory $audited,
        private readonly ValueComparatorInterface $comparator,
        private readonly RowIdentity $identity,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * An execution per change of an audited entity's row, in the order the statements ran.
     *
     * A class of a JOINED hierarchy writes one change as a statement per table it touches, and
     * the persister runs them back to back: so an execution is a fact and the facts right after
     * it -- right after in the log as it ran, not among the facts left once the application's
     * own statements are set aside -- of the same kind and row, in other tables of the
     * hierarchy, in the same frame, with no flush begun between them. Without savepoints a
     * flush nested in another opens no frame, and its first statement can follow the outer
     * flush's last one on the same row; where it began is what says they are two.
     *
     * A statement no flush owns is not a flush's history -- the application's own SQL, or a
     * hole in how flushes claim what they run -- and is left out, and said in the log by the
     * reading that writes the history ({@see NobodysStatement}), as an element's is.
     *
     * @param bool                              $consume      whether this is the reading that writes them
     * @param (\Closure(int): bool)|null        $duringAFlush whether the statement at a position ran while a flush of the listener's did
     * @param (\Closure(\Throwable): void)|null $failed       what is done with a failure building one execution's record -- a
     *                                                         representer of the application's that threw: that record is left
     *                                                         out and the others are read; without it, the failure is raised
     *
     * Each record's changes are given twice: with its context -- the always-recorded fields
     * as the row held them once the whole change ran -- and bare, with the context apart, for
     * a caller that folds more into the record and gives it its context afterwards.
     *
     * Only what came after $readThrough, which the listener moves where a flush's state is
     * forgotten.
     *
     * @return list<array{event: string, class: class-string, entity: object|null, objectType: string, id: int|string, flush: int, at: list<int>, changes: array<string, Change|mixed>, bare: array<string, Change>, context: array<string, mixed>}>
     */
    public function of(EntityManagerInterface $em, HistoryReplay $replay, StatementLog $log, int $readThrough, ?DepartedObjects $departed = null, bool $consume = false, ?\Closure $duringAFlush = null, ?\Closure $failed = null): array
    {
        /** @var list<array{statement: string, class: class-string, id: string, key: array<string, mixed>, flush: int|null, at: list<int>, tables: array<string, true>, fields: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>}> $executions */
        $executions = [];
        $open = null;

        foreach ($replay->rowFacts() as $fact) {
            if ($fact['at'] <= $readThrough) {
                continue;
            }

            $table = StatementShape::read($log->statement($fact['at'])['sql'] ?? '')?->table;
            $execution = $open === null ? null : $executions[$open];

            if ($execution !== null && $table !== null && self::continues($log, $execution, $fact, $table)) {
                $execution['at'][] = $fact['at'];
                $execution['tables'][$table] = true;
                $execution['fields'] = array_replace($execution['fields'], $fact['fields']);
                $execution['context'] = $fact['context'];
                $executions[$open] = $execution;

                continue;
            }

            $executions[] = [
                'statement' => $fact['statement'],
                'class' => $fact['class'],
                'id' => $fact['id'],
                'key' => $fact['key'],
                'flush' => $fact['flush'],
                'at' => [$fact['at']],
                'tables' => [(string) $table => true],
                'fields' => $fact['fields'],
                // The row once the whole change is in the database: the last statement's, as
                // each table of the change is written.
                'context' => $fact['context'],
            ];
            $open = array_key_last($executions);
        }

        $executions = self::withTheirCompletions($em, $log, $executions);
        $builder = new ChangeSetBuilder($em, $this->comparator);
        $runs = [];

        foreach ($executions as $execution) {
            if ($execution['flush'] === null) {
                // What it did that the history would have said, if anything: a statement of
                // nothing audited is nothing to say.
                if ($consume && $execution['fields'] !== []) {
                    NobodysStatement::say($this->logger, $duringAFlush !== null && $duringAFlush($execution['at'][0]), implode(', ', array_keys($execution['fields'])), $execution['class'], array_keys($execution['key']));
                }

                continue;
            }

            try {
                $record = $this->recordOf($em, $replay, $departed, $builder, $execution);
            } catch (\Throwable $e) {
                if ($failed === null) {
                    throw $e;
                }

                $failed($e);

                continue;
            }

            if ($record !== null) {
                $runs[] = $record;
            }
        }

        return $runs;
    }

    /**
     * A creation with the UPDATE Doctrine finished it with, as one creation.
     *
     * Two new rows that point at each other are a cycle no order of INSERTs satisfies: Doctrine
     * inserts one with its reference empty and fills it in afterwards, in an UPDATE nobody
     * announces (UnitOfWork::scheduleExtraUpdate()). That UPDATE is the rest of the creation,
     * not a change: what the history says is that the row appeared pointing where it points.
     *
     * Told apart by its shape, and only by that: of a row the same flush created, with no flush
     * begun since, writing nothing but foreign keys its INSERT left empty. A change the
     * application makes afterwards is anything else -- through a flush, which begins one, or a
     * statement of its own that writes a column the INSERT gave a value to, or one that is not a
     * reference -- and stays a change. A statement of the application's own that sets exactly
     * such a reference, and nothing more, in the same flush, reads as the same completion: the
     * log holds nothing that tells the two apart.
     *
     * @param list<array{statement: string, class: class-string, id: string, key: array<string, mixed>, flush: int|null, at: list<int>, tables: array<string, true>, fields: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>}> $executions
     *
     * @return list<array{statement: string, class: class-string, id: string, key: array<string, mixed>, flush: int|null, at: list<int>, tables: array<string, true>, fields: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>}>
     */
    private static function withTheirCompletions(EntityManagerInterface $em, StatementLog $log, array $executions): array
    {
        $kept = [];
        $creations = [];

        foreach ($executions as $execution) {
            $row = $execution['class'].'|'.$execution['id'];

            // Asked of the INSERT, not of what came between: Doctrine runs its completions once
            // every INSERT has, and a listener's own statement may have run in between.
            $at = $creations[$row] ?? null;
            $creation = $at === null ? null : $kept[$at];

            if ($creation !== null && $execution['statement'] === StatementShape::UPDATE && self::completes($em, $log, $creation, $execution)) {
                foreach ($execution['fields'] as $field => $sides) {
                    $creation['fields'][$field] = ['old' => null, 'new' => $sides['new']];
                }

                $creation['at'] = [...$creation['at'], ...$execution['at']];
                $creation['context'] = $execution['context'];
                $kept[$at] = $creation;

                continue;
            }

            $kept[] = $execution;

            if ($execution['statement'] === StatementShape::INSERT) {
                $creations[$row] = array_key_last($kept);
            }
        }

        return $kept;
    }

    /**
     * Whether an UPDATE is the rest of a creation: {@see withTheirCompletions()}.
     *
     * @param array{class: class-string, flush: int|null, at: list<int>} $creation
     * @param array{class: class-string, flush: int|null, at: list<int>} $update
     */
    private static function completes(EntityManagerInterface $em, StatementLog $log, array $creation, array $update): bool
    {
        if ($creation['flush'] === null || $update['flush'] !== $creation['flush']) {
            return false;
        }

        for ($at = $creation['at'][\count($creation['at']) - 1]; $at < $update['at'][0]; ++$at) {
            if ($log->aFlushStartedAfter($at)) {
                return false;
            }
        }

        $metadata = $em->getClassMetadata($creation['class']);
        $references = [];

        foreach ($metadata->getAssociationNames() as $association) {
            if ($metadata->isSingleValuedAssociation($association) && !$metadata->isAssociationInverseSide($association)) {
                foreach ((array) CollectionRowsQuery::entry($metadata->getAssociationMapping($association), 'joinColumns') as $column) {
                    $name = CollectionRowsQuery::entry($column, 'name');

                    if (\is_string($name)) {
                        $references[$name] = true;
                    }
                }
            }
        }

        // What the INSERT gave each column it wrote.
        $inserted = [];

        foreach ($creation['at'] as $at) {
            $statement = $log->statement($at);
            $shape = StatementShape::read($statement['sql'] ?? '');

            foreach ($shape === null ? [] : $shape->assigned as $column => $parameter) {
                $inserted[$column] = $parameter === null ? true : ($statement['params'][$parameter] ?? null);
            }
        }

        foreach ($update['at'] as $at) {
            $shape = StatementShape::read($log->statement($at)['sql'] ?? '');

            if ($shape === null || $shape->assigned === []) {
                return false;
            }

            foreach ($shape->assigned as $column => $parameter) {
                if ($parameter === null || !isset($references[$column]) || !\array_key_exists($column, $inserted) || $inserted[$column] !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether a fact is the next table of the execution before it.
     *
     * Each condition is here for a case of its own, and each was seen to be the only one that
     * told that case apart (WhereOneExecutionEndsTest):
     *
     * - the next position in the log, not the next fact: the application's statement between
     *   two changes of the row is no fact of it, and still parts them;
     * - no flush begun between: without savepoints a nested flush's first statement can follow
     *   the outer flush's last one on the same row, in the same frame, under the same owner;
     * - the same flush: a flush nested in a transaction of the application's, without savepoints,
     *   opens no frame -- the application's is the one both run in -- and each flush claims what
     *   ran while it did, so back from the nested flush the outer flush's next statement is in
     *   the same frame under another owner. Redundant by construction where every nested flush
     *   has a savepoint (DBAL 4 always, DBAL 3 with use_savepoints): two owners are two frames
     *   there. Kept for where they are not, not as dead code;
     * - no table twice: one table cannot be two tables of one change;
     * - the same frame: a savepoint statement takes no place in the log, so being next to each
     *   other does not make two statements one frame's -- the application's own savepoint, with
     *   no flush in it, between the root's statement and the subclass's.
     *
     * What none of them can tell apart, by the SQL, its parameters and the boundaries the log
     * keeps: the application's statement on the other table of the row, right after the flush's,
     * in the flush's own frame, reads as one change written a table at a time -- nothing of it
     * lost, but two hands not told apart.
     *
     * @param array{statement: string, class: class-string, id: string, flush: int|null, at: list<int>, tables: array<string, true>} $execution
     * @param array{statement: string, class: class-string, id: string, at: int, flush: int|null}                                    $fact
     */
    private static function continues(StatementLog $log, array $execution, array $fact, string $table): bool
    {
        $last = $execution['at'][\count($execution['at']) - 1];

        return $fact['at'] === $last + 1
            && !$log->aFlushStartedAfter($last)
            && $fact['statement'] === $execution['statement']
            && $fact['class'] === $execution['class']
            && $fact['id'] === $execution['id']
            && $fact['flush'] === $execution['flush']
            && !isset($execution['tables'][$table])
            && $log->frameOf($fact['at']) === $log->frameOf($last);
    }

    /**
     * @param array{statement: string, class: class-string, id: string, key: array<string, mixed>, flush: int|null, at: list<int>, fields: array<string, array{old: mixed, new: mixed}>, context: array<string, mixed>} $execution
     *
     * @return array{event: string, class: class-string, entity: object|null, objectType: string, id: int|string, flush: int, at: list<int>, changes: array<string, Change|mixed>, bare: array<string, Change>, context: array<string, mixed>}|null
     */
    private function recordOf(EntityManagerInterface $em, HistoryReplay $replay, ?DepartedObjects $departed, ChangeSetBuilder $builder, array $execution): ?array
    {
        if ($execution['flush'] === null) {
            return null;
        }

        $metadata = $em->getClassMetadata($execution['class']);
        $entity = $this->identity->managed($em, $execution['class'], $execution['key']);
        $declaration = $entity !== null ? $this->audited->for($entity) : $this->audited->forClass($metadata->name, $metadata->newInstance(...));
        $id = $this->identity->historyId($em, $execution['class'], $execution['key']);
        $event = self::EVENTS[$execution['statement']] ?? null;

        if ($declaration === null || $id === null || $event === null) {
            return null;
        }

        $changes = [];
        $bare = [];

        if ($event !== AuditEvent::REMOVE) {
            $changeSet = [];

            foreach ($execution['fields'] as $field => $sides) {
                $changeSet[$field] = $metadata->isSingleValuedAssociation($field)
                    ? [
                        $this->related($em, $replay, $departed, $metadata, $field, $sides['old'], $execution['at'][0], false),
                        $this->related($em, $replay, $departed, $metadata, $field, $sides['new'], $execution['at'][\count($execution['at']) - 1], true),
                    ]
                    : [$sides['old'], $sides['new']];
            }

            $object = $entity ?? $metadata->newInstance();
            $ofTheRow = $this->ofTheRow($em, $metadata, $declaration);
            $bare = $builder->build($object, new AuditMetadata($ofTheRow->objectType, $ofTheRow->fields), $changeSet);
            $changes = $builder->withAlwaysRecorded($object, $ofTheRow, $bare, $execution['context']);
        }

        return [
            'event' => $event,
            'class' => $execution['class'],
            'entity' => $entity,
            'objectType' => $declaration->objectType,
            'id' => $id,
            'flush' => $execution['flush'],
            'at' => $execution['at'],
            'changes' => $changes,
            'bare' => $bare,
            'context' => $event === AuditEvent::REMOVE ? [] : $execution['context'],
        ];
    }

    /**
     * The entity a foreign key the row holds names, as a representer is handed one -- the same
     * rule as for an owning ManyToMany's targets ({@see LinkRuns}). In order:
     *
     * - for a row of a watched class the replay knows there, a copy of it as it stood at the
     *   moment of the execution ({@see HistoryReplay::copyAt()}): the old side before its first
     *   statement, the new one once its last ran -- whatever the object was changed to after,
     *   and pointing where that row pointed (what it points at is the manager's: the row is
     *   shown as it stood, not all it leads to);
     * - for one a DELETE this replay read took before that moment, a copy of it as the row
     *   stood before it went ({@see HistoryReplay::asItStoodBeforeItWent()});
     * - for one of any other class the application removed, the object it held
     *   ({@see DepartedObjects}), as it left it: the bundle does not read those rows;
     * - and otherwise the object the manager holds, or a reference, which the representer loads
     *   if it reads the row. A row that turns out not to be there is named by its identifier
     *   ({@see ofTheRow()}).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function related(EntityManagerInterface $em, HistoryReplay $replay, ?DepartedObjects $departed, ClassMetadata $metadata, string $association, mixed $key, int $at, bool $once): ?object
    {
        if ($key === null) {
            return null;
        }

        $target = $metadata->getAssociationTargetClass($association);
        $columns = $em->getClassMetadata($target)->getIdentifierColumnNames();
        $row = \count($columns) === 1 ? [$columns[0] => $key] : null;

        return ($row === null ? null : $replay->copyAt($target, $row, $at, $once))
            ?? ($row === null ? null : $replay->asItStoodBeforeItWent($target, $row))
            ?? ($row === null ? null : $departed?->find($em, $target, $row))
            ?? $this->identity->byForeignKey($em, $target, $key, orReference: true);
    }

    /**
     * The declaration without its collections: what the entity's row can say.
     *
     * And each representer of a reference answering for a row that is not there: a reference
     * the representer loads, to a row that has gone -- no DELETE this listener read took it,
     * or nobody watches its class -- is named by its identifier rather than failing the
     * record. Only that: whatever else a representer throws is the application's failure, and
     * goes through the policy as it does.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function ofTheRow(EntityManagerInterface $em, ClassMetadata $metadata, AuditMetadata $declaration): AuditMetadata
    {
        $fields = [];

        foreach ($declaration->fields as $field => $represent) {
            if ($metadata->isCollectionValuedAssociation($field)) {
                continue;
            }

            $fields[$field] = $represent === null ? null : function (object $related) use ($em, $represent): mixed {
                try {
                    return $represent($related);
                } catch (EntityNotFoundException) {
                    return $this->identity->idOf($em, $related);
                }
            };
        }

        return new AuditMetadata(
            $declaration->objectType,
            $fields,
            array_values(array_filter($declaration->alwaysRecorded, static fn (string $field): bool => \array_key_exists($field, $fields))),
        );
    }
}
