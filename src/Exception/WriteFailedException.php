<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;

/**
 * A record could not be written and the failure policy is "throw".
 * With the default "log" policy this is never raised — the failure is logged instead.
 */
final class WriteFailedException extends \RuntimeException implements AuditException
{
    private function __construct(string $message, public readonly AuditRecord $record, \Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function for(AuditRecord $record, \Throwable $previous): self
    {
        return new self(
            sprintf('Writing the audit record %s#%s (%s) failed: %s', $record->objectType, $record->objectId, $record->event, $previous->getMessage()),
            $record,
            $previous,
        );
    }
}
