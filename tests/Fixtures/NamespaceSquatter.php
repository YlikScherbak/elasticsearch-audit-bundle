<?php

declare(strict_types=1);

/*
 * A class an application can write, declared inside the bundle's own exception
 * namespace — which PHP allows any file to do, from any package.
 *
 * That is the point: "it starts with our namespace" reads like a boundary and is not
 * one, so the trusted set is named class by class instead. Loaded by hand where it is
 * used; the autoloader maps this namespace to src/, and this is deliberately not there.
 */

namespace Borsche\ElasticsearchAuditBundle\Exception {
    final class SquattedSafeException extends \RuntimeException implements SafeExceptionMessage
    {
    }
}
