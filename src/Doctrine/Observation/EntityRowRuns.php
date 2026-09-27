<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\ChangeSetBuilder;
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
                // The first table's: the replay holds an UPDATE's row whole, and whatever a
                // creation's later tables add is among its fields, which a context gives way to.
                'context' => $fact['context'],
            ];
            $open = array_key_last($executions);
        }

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
