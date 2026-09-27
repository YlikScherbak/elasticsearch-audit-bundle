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
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * What each execution did to the row of an audited entity, turned from the facts of the
 * connection's log into what the listener's records say: the event, the entity, the flush,
 * the statements it was, and the changes keyed as the history names them.
 *
 * Read by nothing yet: step 5 switches the listener's entity records to it (5.2c), and until
 * then the records are built from Doctrine's events.
 *
 * What an owning collection went through is not here: its join rows are no fact of the
 * entity's row, and until the log's facts of them are read (5.3) it is taken from the object
 * while Doctrine still has the collection's snapshot, which it replaces before postFlush.
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
     * hole in how flushes claim what they run -- and is left out here; what is said of it is
     * the caller's.
     *
     * Only what came after $readThrough, which the listener moves where a flush's state is
     * forgotten.
     *
     * @return list<array{event: string, class: class-string, entity: object|null, objectType: string, id: int|string, flush: int, at: list<int>, changes: array<string, Change>}>
     */
    public function of(EntityManagerInterface $em, HistoryReplay $replay, StatementLog $log, int $readThrough): array
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
            $record = $this->recordOf($em, $builder, $execution);

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
     * @return array{event: string, class: class-string, entity: object|null, objectType: string, id: int|string, flush: int, at: list<int>, changes: array<string, Change>}|null
     */
    private function recordOf(EntityManagerInterface $em, ChangeSetBuilder $builder, array $execution): ?array
    {
        if ($execution['flush'] === null) {
            return null;
        }

        $metadata = $em->getClassMetadata($execution['class']);
        $entity = $this->identity->managed($em, $execution['class'], $execution['key']);
        $declaration = $this->audited->for($entity ?? $metadata->newInstance());
        $id = $this->identity->historyId($em, $execution['class'], $execution['key']);
        $event = self::EVENTS[$execution['statement']] ?? null;

        if ($declaration === null || $id === null || $event === null) {
            return null;
        }

        $changes = [];

        if ($event !== AuditEvent::REMOVE) {
            $changeSet = [];

            foreach ($execution['fields'] as $field => $sides) {
                $changeSet[$field] = $metadata->isSingleValuedAssociation($field)
                    ? [$this->related($em, $metadata, $field, $sides['old']), $this->related($em, $metadata, $field, $sides['new'])]
                    : [$sides['old'], $sides['new']];
            }

            $changes = $builder->build($entity ?? $metadata->newInstance(), self::ofTheRow($metadata, $declaration), $changeSet, [], $execution['context']);
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
        ];
    }

    /**
     * The entity a foreign key the row holds names, as a representer is handed one: the object
     * the manager holds, or a reference to it.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function related(EntityManagerInterface $em, ClassMetadata $metadata, string $association, mixed $key): ?object
    {
        return $this->identity->byForeignKey($em, $metadata->getAssociationTargetClass($association), $key, orReference: true);
    }

    /**
     * The declaration without its collections: what the entity's row can say.
     *
     * @param ClassMetadata<object> $metadata
     */
    private static function ofTheRow(ClassMetadata $metadata, AuditMetadata $declaration): AuditMetadata
    {
        $fields = array_filter($declaration->fields, static fn (string $field): bool => !$metadata->isCollectionValuedAssociation($field), \ARRAY_FILTER_USE_KEY);

        return new AuditMetadata(
            $declaration->objectType,
            $fields,
            array_values(array_filter($declaration->alwaysRecorded, static fn (string $field): bool => \array_key_exists($field, $fields))),
        );
    }
}
