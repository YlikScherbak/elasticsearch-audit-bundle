<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Shop;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A key of two columns: its join table's rows are named by both, and a foreign key to it is two
 * columns too ({@see Slot}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'Bay', schema: 'Shop')]
#[Auditable(type: 'bay')]
class Bay
{
    /** @var Collection<int, Label> */
    #[ORM\ManyToMany(targetEntity: Label::class)]
    #[ORM\JoinTable(name: 'Bay_Label', schema: 'Shop')]
    #[ORM\JoinColumn(name: 'bay_code', referencedColumnName: 'code')]
    #[ORM\JoinColumn(name: 'bay_aisle', referencedColumnName: 'aisle')]
    #[ORM\InverseJoinColumn(name: 'label_id', referencedColumnName: 'id')]
    #[AuditField]
    public Collection $labels;

    public function __construct(
        #[ORM\Id, ORM\Column(length: 8)]
        public string $code,
        #[ORM\Id, ORM\Column]
        public int $aisle,
    ) {
        $this->labels = new ArrayCollection();
    }
}
