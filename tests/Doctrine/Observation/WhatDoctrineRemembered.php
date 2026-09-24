<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

use Borsche\ElasticsearchAuditBundle\Doctrine\CollectionRowsQuery;
use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\StatementLog;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * What Doctrine remembered of each row, copied at the first moment of an operation it can be
 * trusted -- TEST-ONLY, and the candidate for where the listener takes its old sides from in
 * phase 3.
 *
 * At preFlush, before computeChangeSets() writes the planned values over it, and at postLoad
 * for a row first loaded inside the operation. The earliest copy of a row wins: a flush refused
 * in onFlush leaves Doctrine's memory holding values the row never took, and the next preFlush's
 * copy is later than the one taken before it.
 *
 * Copied as columns and database values, a foreign key as the key it names, never as an
 * object: the owner has to be found again by its key, and the object may be detached or
 * changed by then.
 *
 * It does not cover a refusal in an EARLIER operation: Doctrine's memory is already wrong when
 * this operation's first preFlush copies it. That is what the other source, the rows read
 * before the scenario, is compared against.
 */
final class WhatDoctrineRemembered
{
    /** @var array<class-string, array<string, array<string, mixed>>> root class => key => column => database value */
    public array $rows = [];

    /**
     * Where the log stood when each row was copied: a copy is what the row held before the
     * statements after that point, and says nothing about the ones before it. A row the
     * operation inserted is copied by the next preFlush, after its INSERT, and is not a row
     * that was there.
     *
     * @var array<class-string, array<string, int>>
     */
    public array $copiedAt = [];

    /**
     * @param list<class-string> $classes
     */
    public function __construct(private readonly array $classes, private readonly StatementLog $log)
    {
    }

    public function preFlush(PreFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();

        foreach ($em->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                $this->copy($em, $entity);
            }
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $em = $args->getObjectManager();

        if ($em instanceof EntityManagerInterface) {
            $this->copy($em, $args->getObject());
        }
    }

    /**
     * Asked of the manager the event came from: after a flush dies the application goes on
     * with a fresh one, on the same listeners.
     */
    private function copy(EntityManagerInterface $em, object $entity): void
    {
        $metadata = $em->getClassMetadata($entity::class);

        if (!\in_array($metadata->rootEntityName, $this->classes, true)) {
            return;
        }

        $original = $em->getUnitOfWork()->getOriginalEntityData($entity);

        if ($original === []) {
            return; // not loaded yet: nothing to remember
        }

        $platform = $em->getConnection()->getDatabasePlatform();
        $row = [];

        foreach ($metadata->getFieldNames() as $field) {
            $type = $metadata->getTypeOfField($field);
            $value = $original[$field] ?? null;
            $row[$metadata->getColumnName($field)] = $value === null || !\is_string($type) ? $value : \Doctrine\DBAL\Types\Type::getType($type)->convertToDatabaseValue($value, $platform);
        }

        foreach ($metadata->getAssociationNames() as $association) {
            if (!$metadata->isSingleValuedAssociation($association) || $metadata->isAssociationInverseSide($association)) {
                continue;
            }

            $columns = CollectionRowsQuery::entry($metadata->getAssociationMapping($association), 'joinColumns');
            $target = $original[$association] ?? null;

            foreach (\is_array($columns) ? $columns : [] as $column) {
                $name = CollectionRowsQuery::entry($column, 'name');
                $referenced = CollectionRowsQuery::entry($column, 'referencedColumnName');

                if (!\is_string($name) || !\is_string($referenced)) {
                    continue;
                }

                if (!\is_object($target)) {
                    $row[$name] = null;

                    continue;
                }

                $targetMetadata = $em->getClassMetadata($target::class);
                $row[$name] = $targetMetadata->getFieldValue($target, $targetMetadata->getFieldForColumn($referenced));
            }
        }

        // The key from the entity, not from Doctrine's memory: computeChangeSets() rewrites
        // that memory without a generated identifier, so every copy taken after a flush planned
        // a change to the row came out keyless -- read as another row, an emptying that took two
        // was said to know of three; skipped, a refused flush's rewritten memory was never put
        // to the rule that the first copy wins, and only looked handled.
        foreach ($metadata->getIdentifierValues($entity) as $field => $value) {
            $row[$metadata->getColumnName($field)] = $value;
        }

        foreach ($metadata->getIdentifierColumnNames() as $column) {
            if (($row[$column] ?? null) === null) {
                return; // not inserted yet: there is no row to remember
            }
        }

        $key = ShadowHistory::keyOf($metadata, $row);

        if (!isset($this->rows[$metadata->rootEntityName][$key])) {
            $this->rows[$metadata->rootEntityName][$key] = $row;
            $this->copiedAt[$metadata->rootEntityName][$key] = $this->log->position();
        }
    }
}
