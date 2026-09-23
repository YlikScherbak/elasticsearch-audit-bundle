<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

/**
 * The same, with savepoints turned on -- which is how DoctrineBundle configures DBAL 3 when
 * use_savepoints is set, and all DBAL 4 does. On DBAL 3 this is the configuration in which a
 * nested flush's frame is seen; the other run measures what is lost without it.
 */
final class WhoseStatementItIsWithSavepointsTest extends WhoseStatementItIsTest
{
    protected bool $savepoints = true;
}
