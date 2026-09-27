<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Observation;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Which entity a row's key names, and the id the history names it by: a key the log carries is
 * the database's form, and an identifier is the object's.
 *
 * One place for it, used by every reader of the log's facts: two readers turning a key into an
 * identifier each their own way is two answers to whose history a statement is.
 *
 * @internal the listener's readers of the log
 */
final class RowIdentity
{
    /**
     * @param \Closure(EntityManagerInterface, object): (int|string|null)               $identifierOf   the id the history names an entity by
     * @param \Closure(EntityManagerInterface, array<string, mixed>): (int|string|null) $identifierFrom the same, from an identifier's values
     */
    public function __construct(
        private readonly \Closure $identifierOf,
        private readonly \Closure $identifierFrom,
    ) {
    }

    /**
     * The entity a foreign key names: the one the manager holds, or -- when asked to -- a
     * reference to it. Only a single-column key is followed, as the replay follows one.
     *
     * @param class-string $class
     */
    public function byForeignKey(EntityManagerInterface $em, string $class, mixed $key, bool $orReference): ?object
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
     * A row's identifier as the object holds it, from the row's key; null when the key does not
     * have every column of it.
     *
     * @param class-string         $class
     * @param array<string, mixed> $key column => database value
     *
     * @return array<string, mixed>|null
     */
    public function identifierValues(EntityManagerInterface $em, string $class, array $key): ?array
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

        return $values;
    }

    /**
     * The entity the manager holds for a row, or null.
     *
     * @param class-string         $class
     * @param array<string, mixed> $key column => database value
     */
    public function managed(EntityManagerInterface $em, string $class, array $key): ?object
    {
        $values = $this->identifierValues($em, $class, $key);
        $found = $values === null ? null : $em->getUnitOfWork()->tryGetById($values, $em->getClassMetadata($class)->rootEntityName);

        return \is_object($found) ? $found : null;
    }

    /**
     * The identifier the history names a row's entity by, from its key: the entity's own when
     * the manager still holds it, and otherwise the same form, from the values.
     *
     * @param class-string         $class
     * @param array<string, mixed> $key column => database value
     */
    public function historyId(EntityManagerInterface $em, string $class, array $key): int|string|null
    {
        $values = $this->identifierValues($em, $class, $key);

        if ($values === null) {
            return null;
        }

        $found = $em->getUnitOfWork()->tryGetById($values, $em->getClassMetadata($class)->rootEntityName);

        return \is_object($found) ? ($this->identifierOf)($em, $found) : ($this->identifierFrom)($em, $values);
    }

    /** The id the history names an entity by. */
    public function idOf(EntityManagerInterface $em, object $entity): int|string|null
    {
        return ($this->identifierOf)($em, $entity);
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
