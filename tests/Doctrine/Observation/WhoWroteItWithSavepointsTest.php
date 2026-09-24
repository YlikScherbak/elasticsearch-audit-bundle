<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine\Observation;

/**
 * The same, with savepoints on: DBAL 4 always, DBAL 3 when use_savepoints is set.
 */
final class WhoWroteItWithSavepointsTest extends WhoWroteItTest
{
    protected bool $savepoints = true;
}
