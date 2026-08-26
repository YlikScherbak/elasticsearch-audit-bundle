<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * Marker for everything the bundle throws, so a consumer can catch it in one place.
 */
interface AuditException extends \Throwable
{
}
