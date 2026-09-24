<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Contract\ValueComparatorInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\ChangeSetBuilder;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Model\Change;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
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
    /**
     * @param \Closure(EntityManagerInterface, object): (int|string|null)               $identifierOf   the id the history names an entity by
     * @param \Closure(EntityManagerInterface, array<string, mixed>): (int|string|null) $identifierFrom the same, from an identifier's values
     */
    public function __construct(
        private readonly AuditMetadataFactory $audited,
        private readonly ValueComparatorInterface $comparator,
        private readonly LoggerInterface $logger,
        private readonly \Closure $identifierOf,
        private readonly \Closure $identifierFrom,
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
     * @return list<array{owner: object|null, flush: int, changes: array<string, Change>}>
     */
    public function of(EntityManagerInterface $em, HistoryReplay $replay, int $readThrough, bool $consume = false): array
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
                if ($consume) {
                    $this->logger->warning('A statement changed {field} of the {class} row {key} outside every flush this listener saw, so it is not in the history: SQL the application ran itself, or a flush that did not claim what it ran.', [
                        'field' => $element['field'],
                        'class' => $element['class'],
                        'key' => json_encode($element['key']),
                    ]);
                }

                continue;
            }

            $owner = $this->ownerByKey($em, $element['owner'], $element['ownerKey'], $consume);
            $declaration = $this->audited->for($owner ?? $em->getClassMetadata($element['owner'])->newInstance());
            $wanted = $declaration?->trackedElementFields($element['collection']);
            $elementId = $this->elementIdByKey($em, $element['class'], $element['key']);

            if ($declaration === null || $wanted === null || $elementId === null) {
                continue;
            }

            $change = $builder->elementFieldChange($declaration->objectType, $element['collection'], $elementId, $element['field'], $fact['old'], $fact['new'], $wanted);

            if ($change === null) {
                continue;
            }

            [$name, $value] = $change;
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

        return $runs;
    }

    /**
     * The owner a foreign key names: the one the manager holds, or -- when asked to -- a
     * reference to it. Only a single-column key is followed, as the replay follows one.
     *
     * @param class-string $class
     */
    private function ownerByKey(EntityManagerInterface $em, string $class, mixed $key, bool $orReference): ?object
    {
        $metadata = $em->getClassMetadata($class);
        $fields = $metadata->getIdentifierFieldNames();

        if (\count($fields) !== 1 || $key === null) {
            return null;
        }

        $field = $fields[0];
        $id = [$field => $metadata->hasAssociation($field) ? $key : self::phpValue($em, $metadata, $field, $key)];
        $found = $em->getUnitOfWork()->tryGetById($id, $metadata->rootEntityName);

        if (\is_object($found)) {
            return $found;
        }

        return $orReference ? $em->getReference($metadata->name, $id) : null;
    }

    /**
     * The identifier the history names an element by, from its row's key: the entity's own
     * when the manager still holds it, and otherwise the same form, from the values.
     *
     * @param class-string         $class
     * @param array<string, mixed> $key column => database value
     */
    private function elementIdByKey(EntityManagerInterface $em, string $class, array $key): int|string|null
    {
        $metadata = $em->getClassMetadata($class);
        $values = [];

        foreach ($metadata->getIdentifierFieldNames() as $field) {
            $association = $metadata->hasAssociation($field);
            $raw = $key[$association ? $metadata->getSingleAssociationJoinColumnName($field) : $metadata->getColumnName($field)] ?? null;

            if ($raw === null) {
                return null;
            }

            $values[$field] = $association ? $raw : self::phpValue($em, $metadata, $field, $raw);
        }

        $found = $em->getUnitOfWork()->tryGetById($values, $metadata->rootEntityName);

        return \is_object($found) ? ($this->identifierOf)($em, $found) : ($this->identifierFrom)($em, $values);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function phpValue(EntityManagerInterface $em, ClassMetadata $metadata, string $field, mixed $value): mixed
    {
        $type = $metadata->getTypeOfField($field);

        return \is_string($type) ? Type::getType($type)->convertToPHPValue($value, $em->getConnection()->getDatabasePlatform()) : $value;
    }
}
