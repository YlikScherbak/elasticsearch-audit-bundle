<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * The bundle was asked to do something its configuration does not allow yet:
 * no Elasticsearch client, a Messenger transport without Messenger, and so on.
 */
final class NotConfiguredException extends \LogicException implements AuditException
{
}
