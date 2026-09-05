<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;

/**
 * An embeddable: Doctrine stores its properties as columns of the owning entity and
 * reports them in the change set under "address.city", never under "address".
 */
#[ORM\Embeddable]
class Address
{
    #[ORM\Column]
    public string $city = '';

    #[ORM\Column]
    public string $street = '';

    public function __construct(string $city = '', string $street = '')
    {
        $this->city = $city;
        $this->street = $street;
    }
}
