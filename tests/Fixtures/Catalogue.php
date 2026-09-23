<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An owning many-to-many over the same lines a Crate owns, and under the same name.
 *
 * Two things about it are the point. Emptying it deletes JOIN rows and leaves every line
 * exactly where it was — unlike emptying a Crate, which takes the lines with it — so
 * anything the listener concludes from "this collection is being emptied" has to tell the
 * two apart. And its field is called `items`, like the Crate's, so a rule that recognises
 * an element by the collection's name and the element's id cannot tell whose it is.
 */
#[ORM\Entity]
#[Auditable(type: 'catalogue')]
class Catalogue
{
    #[ORM\Id, ORM\Column(length: 16)]
    public string $code;

    /** @var Collection<int, CrateItem> */
    #[ORM\ManyToMany(targetEntity: CrateItem::class)]
    #[ORM\JoinTable(name: 'catalogue_item')]
    #[ORM\JoinColumn(name: 'catalogue_code', referencedColumnName: 'code')]
    #[ORM\InverseJoinColumn(name: 'item_id', referencedColumnName: 'id')]
    #[AuditField(represent: 'getSkuUnderItsCrate')]
    public Collection $items;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->items = new ArrayCollection();
    }
}
