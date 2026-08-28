<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Doctrine\Metadata;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Borsche\ElasticsearchAuditBundle\Contract\AuditableInterface;

/**
 * Reads the audit declaration of an entity — from AuditableInterface when the
 * class implements it, from #[Auditable]/#[AuditField] otherwise — and caches the
 * attribute form per class. The interface form is not cached: its field list may
 * depend on the instance.
 *
 * @internal used by AuditSubscriber; declare auditing with AuditableInterface or #[Auditable]
 */
final class AuditMetadataFactory
{
    /** @var array<class-string, AuditMetadata|null> */
    private array $attributeMetadata = [];

    /**
     * Null when the object is not audited at all.
     */
    public function for(object $entity): ?AuditMetadata
    {
        if ($entity instanceof AuditableInterface) {
            return new AuditMetadata($entity->getAuditObjectType(), $entity->getAuditedFields(), $entity->getAlwaysRecordedFields());
        }

        $class = $entity::class;

        if (!\array_key_exists($class, $this->attributeMetadata)) {
            $this->attributeMetadata[$class] = $this->fromAttributes(new \ReflectionClass($entity));
        }

        return $this->attributeMetadata[$class];
    }

    public function isAuditable(object $entity): bool
    {
        return $this->for($entity) !== null;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function fromAttributes(\ReflectionClass $class): ?AuditMetadata
    {
        $auditable = $this->findAuditable($class);

        if ($auditable === null) {
            return null;
        }

        $fields = [];

        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                $attributes = $property->getAttributes(AuditField::class);

                if ($attributes === [] || \array_key_exists($property->getName(), $fields)) {
                    continue;
                }

                $represent = $attributes[0]->newInstance()->represent;
                $fields[$property->getName()] = $represent === null ? null : self::representer($represent, $current->getName().'::$'.$property->getName());
            }
        }

        return new AuditMetadata($auditable->type, $fields, $auditable->alwaysRecord);
    }

    /**
     * Turns #[AuditField(represent: 'getName')] into the callable the metadata carries.
     * The method is named in an attribute, so whether it exists can only be answered
     * when a related object shows up — and then the answer names the declaration that
     * was wrong, instead of a PHP error somewhere inside a flush.
     *
     * @return callable(object): mixed
     */
    private static function representer(string $method, string $declaredOn): callable
    {
        return static function (object $related) use ($method, $declaredOn): mixed {
            $callable = [$related, $method];

            if (!\is_callable($callable)) {
                throw new \LogicException(sprintf('#[AuditField(represent: "%s")] on %s names a method %s does not have.', $method, $declaredOn, $related::class));
            }

            return $callable();
        };
    }

    /**
     * Doctrine proxies subclass the entity, so the attribute may sit on a parent.
     *
     * @param \ReflectionClass<object> $class
     */
    private function findAuditable(\ReflectionClass $class): ?Auditable
    {
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            $attributes = $current->getAttributes(Auditable::class);

            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }
}
