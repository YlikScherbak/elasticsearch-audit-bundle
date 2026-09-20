<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

/**
 * Backed by ints, which is the half of "a backed enum" that has to be turned into a
 * string before it can be a document id. A string-backed one needs nothing done to it
 * and so cannot show whether anything is.
 */
enum BayNumber: int
{
    case One = 1;
    case Two = 2;
}
