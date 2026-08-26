<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Model\AuditEvent;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * Records entity lifecycle events during flush().
 *
 * Registered as a Doctrine listener for postPersist, postUpdate, preRemove and
 * postRemove. The remove is recorded in two steps: the record is built in
 * preRemove, while the entity still has its identifier, and written in
 * postRemove, once the delete actually happened.
 *
 * Everything runs inside flush(), so a failure here would abort the transaction —
 * the writer's failure policy decides whether that is wanted (it is not, by default).
 *
 * @internal register through the bundle configuration (doctrine.enabled)
 */
final class AuditSubscriber
{
    /** @var array<int, AuditRecord> records for entities being removed, keyed by object id */
    private array $pendingRemovals = [];

    public function __construct(
        private readonly AuditWriter $writer,
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly bool $skipEmptyUpdates = true,
    ) {
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::CREATE);

        if ($record !== null) {
            $this->writer->write($record);
        }
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::UPDATE);

        if ($record === null || ($this->skipEmptyUpdates && !$record->hasChanges())) {
            return;
        }

        $this->writer->write($record);
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        $record = $this->recordFor($args, AuditEvent::REMOVE, withChanges: false);

        if ($record !== null) {
            $this->pendingRemovals[spl_object_id($args->getObject())] = $record;
        }
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function postRemove(LifecycleEventArgs $args): void
    {
        $key = spl_object_id($args->getObject());
        $record = $this->pendingRemovals[$key] ?? null;
        unset($this->pendingRemovals[$key]);

        if ($record !== null) {
            $this->writer->write($record);
        }
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    private function recordFor(LifecycleEventArgs $args, string $event, bool $withChanges = true): ?AuditRecord
    {
        $entity = $args->getObject();
        $metadata = $this->metadataFactory->for($entity);

        if ($metadata === null) {
            return null;
        }

        $em = $args->getObjectManager();
        $id = $this->identifierOf($em, $entity);

        if ($id === null) {
            return null; // nothing to attach a history to
        }

        $record = new AuditRecord($metadata->objectType, $id, $event);

        if ($withChanges) {
            $record = $record->withChanges((new ChangeSetBuilder($em))->build($entity, $metadata));
        }

        return $record;
    }

    private function identifierOf(EntityManagerInterface $em, object $entity): int|string|null
    {
        $values = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);

        if ($values === []) {
            return null;
        }

        $parts = array_map(self::stringify(...), array_values($values));

        if (\count($parts) === 1) {
            $only = reset($values);

            return \is_int($only) ? $only : $parts[0];
        }

        return implode('|', $parts); // composite key
    }

    /**
     * Identifiers are ints, strings or objects with a string form (Uuid, Ulid, enums).
     */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            default => throw new \LogicException(sprintf('Cannot use a %s as an audit object id.', get_debug_type($value))),
        };
    }
}
