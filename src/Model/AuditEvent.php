<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Model;

/**
 * The three lifecycle events the bundle emits itself. An event is just a string,
 * so applications are free to record their own ("order_call", "login_failed", ...).
 */
final class AuditEvent
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const REMOVE = 'remove';

    private function __construct()
    {
    }

    public static function isLifecycle(string $event): bool
    {
        return \in_array($event, [self::CREATE, self::UPDATE, self::REMOVE], true);
    }
}
