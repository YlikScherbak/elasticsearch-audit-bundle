<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Stores a Sku as the string it prints as. The Doctrine half of the fixture above,
 * registered by DoctrineTestCase.
 */
final class SkuType extends Type
{
    public const NAME = 'sku';

    /**
     * @param array<string, mixed> $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => 32]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Sku
    {
        return $value === null ? null : new Sku((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * DBAL 3 asks for this and DBAL 4 does not, which costs one method rather than a
     * branch on a version number.
     */
    public function getName(): string
    {
        return self::NAME;
    }
}
