<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/**
 * Two always-recorded fields, which is the point of it.
 *
 * An always-recorded field that moved is already in the record as a change, and the
 * pass that gathers context skips it. A second one behind it is the only way to see
 * whether that pass carries on past the skip or stops there — and every other fixture
 * with alwaysRecord names exactly one field.
 *
 * Both are audited, because alwaysRecord refuses a field that is not and says so.
 */
#[ORM\Entity]
#[Auditable(type: 'consignment', alwaysRecord: ['status', 'carrier'])]
class Consignment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column, AuditField]
    public string $status = 'packing';

    /**
     * The one the tests move while a comparator says it did not really move, which is
     * how a field reaches the context pass while Doctrine still holds it in the change
     * set — the ordinary shape of "100 and 100.0 are the same number".
     */
    #[ORM\Column, AuditField]
    public string $carrier = 'none';

    #[ORM\Column, AuditField]
    public string $note = '';

    public function __construct(string $note = '')
    {
        $this->note = $note;
    }
}
