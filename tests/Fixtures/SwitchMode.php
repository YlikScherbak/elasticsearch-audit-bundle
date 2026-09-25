<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Fixtures;

/** A backed enum stored as its value: what a row holds is the string, what an object holds the case. */
enum SwitchMode: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
