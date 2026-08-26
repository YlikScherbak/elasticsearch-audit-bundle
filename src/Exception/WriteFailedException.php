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
    /**
     * @param AuditRecord|null $record null when the failure happened before a record existed
     *                                 (the Doctrine listener could not build one)
     */
    private function __construct(string $message, public readonly ?AuditRecord $record, \Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function for(?AuditRecord $record, \Throwable $previous): self
    {
        $message = $record === null
            ? sprintf('An audit record could not be built: %s', $previous->getMessage())
            : sprintf('Writing the audit record %s#%s (%s) failed: %s', $record->objectType, $record->objectId, $record->event, $previous->getMessage());

        return new self($message, $record, $previous);
    }
}
