<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

use Borsche\ElasticsearchAuditBundle\Attribute\Auditable;
use Borsche\ElasticsearchAuditBundle\Attribute\AuditField;
use Doctrine\ORM\Mapping as ORM;

/** An entity whose audited link its mapped superclass declares. */
#[ORM\Entity]
#[Auditable(type: 'poster')]
class Poster extends Labelled
{
    public function __construct(
        #[ORM\Column, AuditField]
        public string $title = 'poster',
    ) {
        parent::__construct();
    }
}
