<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

/**
 * An identifier that is an object with a string form — the shape every application
 * using Uuid, Ulid or an identifier of its own has.
 *
 * Written here rather than pulled in as symfony/uid: what the listener needs to see is
 * a Stringable in an identifier, and a dependency added to a test suite is a dependency
 * the whole matrix has to keep working with.
 */
final class Sku implements \Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
