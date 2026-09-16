<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audits the column Doctrine writes itself: the optimistic lock.
 */
#[ORM\Entity]
#[Auditable('versioned_order')]
class VersionedOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    #[AuditField]
    public string $reference;

    /**
     * Not called "lock": Doctrine names the column after the property, `lock` is a
     * reserved word in MySQL, and the SELECT that re-reads a version column after the
     * INSERT does not quote it — so the fixture, not the bundle, failed with a syntax
     * error the moment this test met a real MySQL.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    #[AuditField]
    public int $lockVersion = 1;

    public function __construct(string $reference)
    {
        $this->reference = $reference;
    }
}
